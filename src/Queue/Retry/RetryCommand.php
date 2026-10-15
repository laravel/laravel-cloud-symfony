<?php

namespace Laravel\Cloud\Symfony\Queue\Retry;

use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Retries failed jobs from the Laravel Cloud dashboard: Cloud runs this with a
 * signed URL, as it runs `artisan queue:retry <url>` for Laravel. Jobs are
 * re-dispatched through the bus so middleware and send events still run.
 */
#[AsCommand(name: 'cloud:queue:retry', description: 'Retry failed Laravel Cloud managed queue jobs', hidden: true)]
final class RetryCommand extends Command
{
    /**
     * Stamps from the original dispatch that don't apply to the retry.
     */
    private const STALE_STAMPS = [
        DelayStamp::class,
        RedeliveryStamp::class,
        ErrorDetailsStamp::class,
        SentToFailureTransportStamp::class,
        TransportMessageIdStamp::class,
        TransportNamesStamp::class,
        CloudQueueStamp::class,
    ];

    public function __construct(
        private readonly FailedJobFetcher $fetcher,
        private readonly ContainerInterface $transports,
        private readonly MessageBusInterface $bus,
        private readonly Events $events,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Signed Laravel Cloud URL(s) serving the failed jobs')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'The managed-queue transport to retry onto', 'cloud');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $transportName = (string) $input->getOption('transport');
        $transport = $this->transports->has($transportName) ? $this->transports->get($transportName) : null;

        if (! $transport instanceof CloudQueueTransport) {
            $io->error(sprintf('The "%s" transport is not a Laravel Cloud managed queue transport.', $transportName));

            return Command::FAILURE;
        }

        $retried = 0;
        $failed = 0;

        foreach ((array) $input->getArgument('url') as $url) {
            try {
                foreach ($this->fetcher->fetch((string) $url) as $job) {
                    try {
                        $this->retry($job, $transport, $transportName);
                    } catch (\Throwable $e) {
                        // Not forgotten, so it stays failed in the dashboard.
                        $io->error(sprintf('Unable to retry failed job [%s]: %s', $job['id'], $e->getMessage()));
                        $failed++;

                        continue;
                    }

                    $output->writeln(sprintf('Retried failed job [%s].', $job['id']));
                    $retried++;
                }
            } catch (\Throwable $e) {
                $io->error('Unable to fetch failed jobs: '.$e->getMessage());
                $failed++;
            }
        }

        if ($retried === 0 && $failed === 0) {
            $io->info('No retryable jobs found.');
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array{id: string, queue: string, payload: string} $job
     */
    private function retry(array $job, CloudQueueTransport $transport, string $transportName): void
    {
        $envelope = $transport->decodePayload($job['payload']);

        foreach (self::STALE_STAMPS as $stamp) {
            $envelope = $envelope->withoutAll($stamp);
        }

        // App routing may send this message elsewhere, so pin it to the managed queue.
        $envelope = $envelope->with(new TransportNamesStamp([$transportName]));

        if ($job['queue'] !== '') {
            $envelope = $envelope->with(new CloudQueueStamp($job['queue']));
        }

        $this->bus->dispatch($envelope);

        // Laravel's FailedJobProvider::forget(): marks the job retried.
        $this->events->emit([
            '_cloud_event' => 'failed_job',
            'id' => $job['id'],
            'queue' => $job['queue'],
            'retried_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }
}

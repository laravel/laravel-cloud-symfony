<?php

namespace Laravel\Cloud\Symfony\Tests;

use Aws\CommandInterface;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Shared harness for the retry integration tests. It wires a CloudQueueTransport
 * to an AWS MockHandler that records the SQS commands, builds the real Symfony
 * retry listener around it, and produces the failed event the way the transport's
 * decode() would (SQS coordinates + a RedeliveryStamp reconstructed from the
 * receive count). The only thing that varies between consumers is the transport
 * name the listener resolves its sender/strategy under — supplied by
 * retryTransportName() — which is exactly the axis ForeignTransportRetryTest
 * pins down.
 */
trait InteractsWithRetryTransport
{
    private const QUEUE_URL = 'https://sqs.us-east-2.amazonaws.com/036991040854/default-env-x';

    /** @var array<int, array<string, mixed>> */
    private array $sqsCommands = [];

    /**
     * The name the worker runs the transport under; the listener looks up its
     * sender and strategy by it. The retry path is name-independent, so this can
     * be 'cloud' or any custom alias.
     */
    abstract protected function retryTransportName(): string;

    private function failureFor(int $receiveCount, ?\Throwable $error = null): WorkerMessageFailedEvent
    {
        // Mirror what the transport's decode() attaches on receive: the SQS
        // coordinates plus a RedeliveryStamp reconstructed from the receive count,
        // which is what lets the stock strategy count across native redeliveries.
        $envelope = (new Envelope(new DummyMessage('x')))
            ->with(new CloudQueueReceivedStamp('m-1', 'rh-1', self::QUEUE_URL, fromAgent: false, approximateReceiveCount: $receiveCount))
            ->with(new RedeliveryStamp(max(0, $receiveCount - 1)));

        return new WorkerMessageFailedEvent($envelope, $this->retryTransportName(), $error ?? new \RuntimeException('boom'));
    }

    private function retryListener(CloudQueueTransport $transport, MultiplierRetryStrategy $strategy): SendFailedMessageForRetryListener
    {
        // The listener resolves the sender (for its re-send) and the strategy by
        // transport name; both locators are keyed on the worker's transport name,
        // exactly as they would be for that transport in a real app.
        return new SendFailedMessageForRetryListener(
            $this->locator([$this->retryTransportName() => $transport]),
            $this->locator([$this->retryTransportName() => $strategy]),
        );
    }

    /**
     * @param array<string, object> $services
     */
    private function locator(array $services): ContainerInterface
    {
        return new ServiceLocator(array_map(
            static fn (object $service): \Closure => static fn (): object => $service,
            $services,
        ));
    }

    private function transport(): CloudQueueTransport
    {
        $mock = new AwsMockHandler();
        $record = function (CommandInterface $cmd) {
            $this->sqsCommands[] = ['@action' => $cmd->getName()] + $cmd->toArray();

            return new Result([]);
        };
        foreach (range(1, 8) as $ignored) {
            $mock->append($record);
        }

        $config = ManagedQueueConfig::fromEnvironment(
            json_encode(['connection' => [
                'prefix' => 'https://sqs.us-east-2.amazonaws.com/036991040854',
                'suffix' => '-env-x',
                'queue' => 'default',
                'region' => 'us-east-2',
            ]]),
            '/unused.sock',
        );

        $sqs = new SqsClient([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        // useAgent: false → drive SQS directly so the test can inspect the calls.
        return new CloudQueueTransport($sqs, new AgentClient($config), $config, new PhpSerializer(), false);
    }

    private function lastSqsAction(): ?string
    {
        $last = end($this->sqsCommands);

        return $last['@action'] ?? null;
    }
}

<?php

namespace Laravel\Cloud\Symfony\Tests;

use Aws\CommandInterface;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Laravel\Cloud\Symfony\Queue\QueueEventSubscriber;
use Laravel\Cloud\Symfony\Queue\Retry\FailedJobFetcher;
use Laravel\Cloud\Symfony\Queue\Retry\RetryCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Drives `cloud:queue:retry` through a real Messenger bus onto a real transport.
 */
class RetryCommandTest extends TestCase
{
    use CapturesCloudEvents;
    use EncryptsLikeLaravel;

    private const SECRET = '0123456789abcdef0123456789abcdef';

    private const PREFIX = 'https://sqs.us-east-2.amazonaws.com/036991040854';

    /** @var list<array<string, mixed>> */
    private array $sqsCommands = [];

    protected function setUp(): void
    {
        $this->openEventSocket();
    }

    protected function tearDown(): void
    {
        $this->closeEventSocket();
    }

    public function testItRedispatchesTheFailedJobOntoItsQueue(): void
    {
        $payload = $this->failedPayload();

        $tester = $this->tester([$this->page([['id' => 'job-1', 'queue' => 'emails', 'payload' => $payload]])]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['url' => ['https://cloud.test/failed']]));
        $this->assertStringContainsString('Retried failed job [job-1]', $tester->getDisplay());

        $send = $this->lastSqsCommand();
        $this->assertSame('SendMessage', $send['@action']);
        $this->assertSame(self::PREFIX.'/emails-env-x', $send['QueueUrl']);
        $this->assertArrayNotHasKey('DelaySeconds', $send);

        $envelope = $this->transport()->decodePayload($send['MessageBody']);
        $this->assertEquals(new DummyMessage('send the invoice'), $envelope->getMessage());
        $this->assertNull($envelope->last(DelayStamp::class));
    }

    public function testItEmitsTheSendLifecycleAndMarksTheJobRetried(): void
    {
        $payload = $this->failedPayload();

        $tester = $this->tester([$this->page([['id' => 'job-1', 'queue' => 'emails', 'payload' => $payload]])]);

        $lines = $this->capture(fn () => $tester->execute(['url' => ['https://cloud.test/failed']]));

        // Only emitted if the retry went through the bus.
        $this->assertSame('queue', $lines[0]['_cloud_event']);
        $this->assertSame('queued', $lines[0]['type']);
        $this->assertSame('emails', $lines[0]['queue']);

        $this->assertSame('failed_job', $lines[1]['_cloud_event']);
        $this->assertSame('job-1', $lines[1]['id']);
        $this->assertSame('emails', $lines[1]['queue']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $lines[1]['retried_at']);
    }

    public function testAnUndecodablePayloadIsReportedAndTheRestStillRetry(): void
    {
        $tester = $this->tester([$this->page([
            ['id' => 'job-bad', 'queue' => 'emails', 'payload' => '{"not":"a managed queue payload"}'],
            ['id' => 'job-good', 'queue' => 'emails', 'payload' => $this->failedPayload()],
        ])]);

        $this->assertSame(Command::FAILURE, $tester->execute(['url' => ['https://cloud.test/failed']]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Unable to retry failed job [job-bad]', $display);
        $this->assertStringContainsString('Retried failed job [job-good]', $display);
        $this->assertSame('SendMessage', $this->lastSqsCommand()['@action']);
    }

    public function testItRefusesATransportThatIsNotAManagedQueue(): void
    {
        $tester = $this->tester([]);

        $this->assertSame(Command::FAILURE, $tester->execute(['url' => ['https://cloud.test/failed'], '--transport' => 'async']));
        $this->assertStringContainsString('not a Laravel Cloud managed queue transport', $tester->getDisplay());
    }

    /**
     * A real transport payload for a delayed dispatch to "emails".
     */
    private function failedPayload(): string
    {
        $this->transport()->send(new Envelope(new DummyMessage('send the invoice'), [
            new CloudQueueStamp('emails'),
            new DelayStamp(30_000),
        ]));

        $body = $this->lastSqsCommand()['MessageBody'];
        $this->sqsCommands = [];

        return $body;
    }

    /**
     * @param list<Response> $responses
     */
    private function tester(array $responses): CommandTester
    {
        $events = new Events($this->eventSocketAddress());
        $transport = $this->transport();
        $transports = new ServiceLocator(['cloud' => fn () => $transport]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new QueueEventSubscriber($events, $this->config()));
        $bus = new MessageBus([new SendMessageMiddleware(new SendersLocator([], $transports), $dispatcher)]);

        $fetcher = new FailedJobFetcher(self::SECRET, new Client(['handler' => new MockHandler($responses)]), fn () => null);

        return new CommandTester(new RetryCommand($fetcher, $transports, $bus, $events));
    }

    /**
     * @param list<array<string, string>> $jobs
     */
    private function page(array $jobs): Response
    {
        return new Response(200, ['Cloud-Payload-Version' => '1'], self::encryptLikeLaravel(json_encode([
            'data' => $jobs,
            'links' => ['self' => 'https://cloud.test/failed', 'next' => null],
        ]), self::SECRET));
    }

    private function transport(): CloudQueueTransport
    {
        $mock = new AwsMockHandler();

        foreach (range(1, 8) as $ignored) {
            $mock->append(function (CommandInterface $command) {
                $this->sqsCommands[] = ['@action' => $command->getName()] + $command->toArray();

                return new Result(['MessageId' => 'sqs-'.count($this->sqsCommands)]);
            });
        }

        $sqs = new SqsClient([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        return new CloudQueueTransport($sqs, new AgentClient($this->config()), $this->config(), new PhpSerializer(), false);
    }

    private function config(): ManagedQueueConfig
    {
        return ManagedQueueConfig::fromEnvironment(json_encode(['connection' => [
            'prefix' => self::PREFIX,
            'suffix' => '-env-x',
            'queue' => 'default',
            'region' => 'us-east-2',
        ]]), '/unused.sock');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastSqsCommand(): array
    {
        return $this->sqsCommands[array_key_last($this->sqsCommands)] ?? [];
    }
}

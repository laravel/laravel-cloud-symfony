<?php

namespace Laravel\Cloud\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

/**
 * Exercises the *real* Symfony retry listener with the *stock*
 * MultiplierRetryStrategy against the transport, simulating the worker's
 * failed → reject flow. Proves the design the refactor rests on: by bridging
 * SQS's ApproximateReceiveCount into a RedeliveryStamp on receive, the unmodified
 * Symfony retry machinery counts and backs off correctly; the listener re-sends
 * on retry, which send() turns into a visibility reset (no duplicate); and the
 * terminal failure is deleted in reject(). No custom strategy involved.
 *
 * The transport harness lives in InteractsWithRetryTransport; here the worker
 * runs the transport under its default 'cloud' name.
 */
class CloudRetryIntegrationTest extends TestCase
{
    use InteractsWithRetryTransport;

    protected function retryTransportName(): string
    {
        return 'cloud';
    }

    public function testMaxRetriesThreeYieldsThreeVisibilityResetsThenADelete(): void
    {
        $transport = $this->transport();
        // Exactly the stock strategy, wired as the standard retry_strategy config
        // would wire it (jitter off so the backoff is deterministic to assert).
        $listener = $this->retryListener($transport, new MultiplierRetryStrategy(maxRetries: 3, delayMilliseconds: 1000, multiplier: 2.0, maxDelayMilliseconds: 0, jitter: 0.0));

        $actions = [];

        // Deliveries 1..4, the way SQS would redeliver the same message.
        foreach ([1, 2, 3, 4] as $receiveCount) {
            $event = $this->failureFor($receiveCount);

            // The worker dispatches the failed event (the retry listener runs,
            // and on a retry re-sends — which send() turns into a release)...
            $listener->onMessageFailed($event);

            // ...then unconditionally rejects the (now stamped) envelope.
            $transport->reject($event->getEnvelope());

            $actions[$receiveCount] = $this->lastSqsAction();
        }

        // Three retries via visibility reset, then a terminal delete — 4 attempts.
        $this->assertSame([
            1 => 'ChangeMessageVisibility',
            2 => 'ChangeMessageVisibility',
            3 => 'ChangeMessageVisibility',
            4 => 'DeleteMessage',
        ], $actions);

        // One SQS action per delivery; the re-sends never created a duplicate.
        $this->assertCount(4, $this->sqsCommands);
    }

    public function testAnArbitraryMaxRetriesIsHonoured(): void
    {
        // retry_strategy.max_retries: 5 → five visibility resets then a delete,
        // i.e. six total deliveries — straight from the standard config.
        $transport = $this->transport();
        $listener = $this->retryListener($transport, new MultiplierRetryStrategy(maxRetries: 5, delayMilliseconds: 1000, multiplier: 2.0, maxDelayMilliseconds: 0, jitter: 0.0));

        $actions = [];

        foreach ([1, 2, 3, 4, 5, 6] as $receiveCount) {
            $event = $this->failureFor($receiveCount);
            $listener->onMessageFailed($event);
            $transport->reject($event->getEnvelope());
            $actions[$receiveCount] = $this->lastSqsAction();
        }

        $this->assertSame([
            1 => 'ChangeMessageVisibility',
            2 => 'ChangeMessageVisibility',
            3 => 'ChangeMessageVisibility',
            4 => 'ChangeMessageVisibility',
            5 => 'ChangeMessageVisibility',
            6 => 'DeleteMessage',
        ], $actions);
    }

    public function testTheVisibilityResetIsClampedToTwelveHours(): void
    {
        // A high attempt with a big multiplier produces a backoff far beyond the
        // 12-hour SQS ceiling; the reset must clamp to it rather than be rejected.
        $transport = $this->transport();
        $listener = $this->retryListener($transport, new MultiplierRetryStrategy(maxRetries: 100, delayMilliseconds: 1000, multiplier: 10.0, maxDelayMilliseconds: 0, jitter: 0.0));

        // 8th delivery → 1000ms * 10^7 = 10,000,000s, well over the 12h ceiling.
        $event = $this->failureFor(8);
        $listener->onMessageFailed($event);
        $transport->reject($event->getEnvelope());

        $this->assertSame('ChangeMessageVisibility', $this->sqsCommands[0]['@action']);
        $this->assertSame(43_200, $this->sqsCommands[0]['VisibilityTimeout']);
    }

    public function testTheVisibilityResetUsesTheStockStrategyBackoff(): void
    {
        $transport = $this->transport();
        $listener = $this->retryListener($transport, new MultiplierRetryStrategy(maxRetries: 3, delayMilliseconds: 1000, multiplier: 2.0, maxDelayMilliseconds: 0, jitter: 0.0));

        $event = $this->failureFor(2); // second delivery → 1000ms * 2^1 = 2s
        $listener->onMessageFailed($event);
        $transport->reject($event->getEnvelope());

        $this->assertSame('ChangeMessageVisibility', $this->sqsCommands[0]['@action']);
        $this->assertSame(2, $this->sqsCommands[0]['VisibilityTimeout']);
    }

    public function testAnUnrecoverableExceptionFailsImmediatelyEvenOnTheFirstAttempt(): void
    {
        $transport = $this->transport();
        $listener = $this->retryListener($transport, new MultiplierRetryStrategy(maxRetries: 3, jitter: 0.0));

        // First delivery, but the handler threw an unrecoverable exception — the
        // stock listener short-circuits to terminal, so there is no re-send and
        // reject() deletes rather than releasing.
        $event = $this->failureFor(1, new UnrecoverableMessageHandlingException('do not retry'));
        $listener->onMessageFailed($event);
        $transport->reject($event->getEnvelope());

        $this->assertFalse($event->willRetry());
        $this->assertSame('DeleteMessage', $this->lastSqsAction());
    }
}

<?php

namespace Laravel\Cloud\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

/**
 * The transport is selected by DSN (laravel-cloud://managed-queue) in
 * CloudQueueTransportFactory::supports(), NOT by the name it is registered
 * under. So a user is free to register it as 'my_async' (or anything else)
 * pointing at that DSN and still get a CloudQueueTransport.
 *
 * This guards a real regression risk: an earlier design scoped the retry
 * configuration to the literal name 'cloud', which would silently break any
 * transport registered under another name. The retry path is in fact pure
 * Symfony-Messenger machinery keyed on whatever name the worker is running —
 * the listener resolves the sender and the strategy from its locators by that
 * name. This runs the *same* real listener + stock strategy as
 * CloudRetryIntegrationTest (via InteractsWithRetryTransport) but under the name
 * 'my_async', to prove the behaviour is name-independent: the visibility resets
 * and the terminal delete come out identical.
 */
class ForeignTransportRetryTest extends TestCase
{
    use InteractsWithRetryTransport;

    // The point of the whole test: a name that is deliberately NOT 'cloud'.
    protected function retryTransportName(): string
    {
        return 'my_async';
    }

    public function testATransportRegisteredUnderAnotherNameRetriesIdentically(): void
    {
        $transport = $this->transport();
        // Stock strategy with max_retries: 2 → two visibility resets then a delete
        // (three total deliveries). jitter: 0.0 keeps the backoff deterministic.
        $listener = $this->retryListener($transport, new MultiplierRetryStrategy(maxRetries: 2, delayMilliseconds: 1000, multiplier: 2.0, maxDelayMilliseconds: 0, jitter: 0.0));

        $actions = [];

        // Deliveries 1..3, the way SQS would redeliver the same message. The failed
        // event carries the name 'my_async', so the listener resolves its
        // sender/strategy from the 'my_async' slots.
        foreach ([1, 2, 3] as $receiveCount) {
            $event = $this->failureFor($receiveCount);

            $listener->onMessageFailed($event);
            $transport->reject($event->getEnvelope());

            $actions[$receiveCount] = $this->lastSqsAction();
        }

        // Two retries via visibility reset, then a terminal delete — exactly the
        // same shape CloudRetryIntegrationTest asserts under the name 'cloud'.
        $this->assertSame([
            1 => 'ChangeMessageVisibility',
            2 => 'ChangeMessageVisibility',
            3 => 'DeleteMessage',
        ], $actions);

        // One SQS action per delivery; the re-sends never created a duplicate.
        $this->assertCount(3, $this->sqsCommands);
    }
}

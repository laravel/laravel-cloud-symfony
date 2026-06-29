# laravel/symfony-on-cloud

Bring [Laravel Cloud](https://laravel.com/cloud) features to a [Symfony](https://symfony.com)
application.

[Managed Queues](#managed-queues) is the first feature; more Laravel Cloud
capabilities will follow under the same `laravel_cloud` config key.

## Installation

```bash
composer require laravel/symfony-on-cloud
```

Then register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Laravel\Cloud\Symfony\LaravelCloudBundle::class => ['all' => true],
];
```

> The package is not yet on Packagist and ships no Symfony Flex recipe, so the
> bundle is registered manually for now. A recipe could automate this later.

## Managed Queues

Run Laravel Cloud Managed Queues on top of
[Symfony Messenger](https://symfony.com/doc/current/messenger.html).

The bundle ships a ready-made `cloud` transport that always points at your
managed queue — nothing to declare, no DSN to wire up. Just route to it:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            '*': cloud
```

That's it, locally and on Cloud alike. On Cloud the injected config wires
everything up for you, so it just works — no extra setup. Throughput, duration
and failures show up in the Laravel Cloud dashboard automatically.

> **Already running Messenger?** Cloud injects
> `MESSENGER_TRANSPORT_DSN=laravel-cloud://managed-queue`, so an app using the
> conventional `async` transport (`dsn: '%env(MESSENGER_TRANSPORT_DSN)%'`) runs
> on Cloud unchanged. The `cloud` transport is the recommended path because it
> also works locally with no env var to set.

### Multiple queues

A Laravel Cloud environment can have several managed queues (for example
`default` and `critical`), each backed by its own SQS queue and its own isolated
workers. You keep the single `cloud` transport and reference a queue by its
**name** on both sides — like Laravel's `->onQueue()` / `--queue=`, rather than
Symfony's one-transport-per-queue convention.

**Dispatch** to a specific queue with `CloudQueueStamp` — the same pattern as
Symfony's own `AmqpStamp`:

```php
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Symfony\Component\Messenger\MessageBusInterface;

$bus->dispatch(new ProcessReport($report), [new CloudQueueStamp('critical')]);
```

Without a stamp, a message goes to the environment's default queue. To route by
message type, add a middleware that attaches the stamp based on the class.

**Process** a specific queue with Messenger's `--queues` option (Laravel's
`queue:work --queue=critical`):

```bash
php bin/console messenger:consume cloud --queues=critical
```

On Cloud each managed queue gets its own isolated workers, and Cloud wires up
this command with the right queue name for you. Attribution flows to the
dashboard end to end, so a `CloudQueueStamp('critical')` dispatch is recorded
against `critical` on both the dispatch and processing side.

### FIFO queues

A managed queue whose name ends in `.fifo` is a [FIFO queue](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/FIFO-queues.html):
messages are delivered in strict order and deduplicated. Target one exactly like
any other queue — by its `.fifo` name:

```php
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;

$bus->dispatch(new ProcessOrder($order), [new CloudQueueStamp('orders.fifo')]);
```

SQS requires every FIFO message to carry a **message group ID** (messages sharing
a group are ordered relative to each other) and a **deduplication ID**. By default
the bundle sends SQS-safe values — the group ID is the queue name and the
deduplication ID is unique per message — so this works with no extra setup. The
defaults mirror Laravel's SQS driver.

To control ordering scope or deduplicate by a stable key, add a `CloudFifoStamp`
alongside the queue stamp (the field names match Symfony's `AmazonSqsFifoStamp`):

```php
use Laravel\Cloud\Symfony\Queue\Messenger\CloudFifoStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;

$bus->dispatch(new ProcessOrder($order), [
    new CloudQueueStamp('orders.fifo'),
    // Order this customer's jobs together; dedupe re-dispatches of the same order.
    new CloudFifoStamp(
        messageGroupId: 'customer-'.$order->customerId,
        messageDeduplicationId: 'order-'.$order->id,
    ),
]);
```

A `CloudFifoStamp` is only valid on a `.fifo` queue; applying it to a standard
queue throws rather than being silently ignored. FIFO queues don't support a
per-message delivery delay at all, so a caller-set `DelayStamp` is rejected (see
[Delays](#delays) below).

### Fair queues

[SQS fair queues](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-fair-queues.html)
keep one noisy tenant from starving the others on a busy queue. On a **standard**
queue, attaching a message group ID marks each message with the tenant it belongs
to (a customer, client app, or request type); SQS then spreads consumer capacity
fairly across groups when one tenant builds a backlog. Fairness is automatic and
server-side — there's nothing to enable and no change to your consumers.

Unlike FIFO, the group ID here is **only** a tenant key: messages in the same
group still process in parallel at full throughput, and there is no ordering or
deduplication. Attach a `CloudMessageGroupStamp` — the Symfony equivalent of
Laravel's `$job->messageGroup()` / `->onGroup()`:

```php
use Laravel\Cloud\Symfony\Queue\Messenger\CloudMessageGroupStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;

$bus->dispatch(new ProcessOrder($order), [
    // A standard queue (no ".fifo"), so the group ID is a fair-queue tenant key.
    new CloudQueueStamp('orders'),
    new CloudMessageGroupStamp('customer-'.$order->customerId),
]);
```

To attach the tenant key everywhere without repeating it at each dispatch, add a
middleware that stamps `CloudMessageGroupStamp` from the message (the tenant on
the job, the authenticated user, etc.).

The two stamps mirror the two queue models and don't mix: `CloudMessageGroupStamp`
is for fair queues (standard) and is rejected on a `.fifo` queue, where the group
is an ordering scope set via `CloudFifoStamp` instead.

### Delays

A `DelayStamp` on a standard queue maps to an SQS per-message delivery delay,
which SQS hard-caps at **15 minutes**. Asking for a longer delay is unsatisfiable,
so the transport **throws** rather than silently delivering after 15 minutes — a
job that must run in an hour shouldn't quietly run in fifteen.

FIFO queues don't support a per-message delay at all, so a `DelayStamp` on a
`.fifo` queue is rejected outright — same principle, same loud failure.

(This is about *dispatch* delays only. Retry backoff is a separate path that
isn't bound by the 15-minute cap — see [Retries](#retries).)

### Retries

When a handler fails, the transport retries exactly as Laravel does: it **releases
the message back to SQS** for native redelivery via the visibility timeout, rather
than re-sending a fresh copy. The original message stays put and SQS redelivers it
after the backoff — so retries are never re-dispatched, the backoff can run up to
SQS's **12-hour** visibility ceiling (not the 15-minute send cap), and it works
unchanged on FIFO queues. On Cloud the in-container agent performs the release;
locally it's a direct `ChangeMessageVisibility`.

This is driven by **Symfony Messenger's own retry strategy** — there's no separate
config to learn. Under the hood the transport bridges SQS's `ApproximateReceiveCount`
into the retry counter Messenger expects, so the standard `retry_strategy` is fully
respected (including its `UnrecoverableExceptionInterface` handling — an unrecoverable
error, or a handler failure wrapping one, skips straight to failure). Tune it on the
`cloud` transport like any other Messenger transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            cloud:
                retry_strategy:
                    max_retries: 3   # 3 retries → 4 total deliveries
                    delay: 1000      # first backoff, in milliseconds
                    multiplier: 2    # backoff multiplier per attempt
                    max_delay: 0     # backoff ceiling in ms; 0 = none (SQS still caps at 12h)
```

(You only add the `retry_strategy` — the `dsn` comes from the ready-made `cloud`
transport. Omit the block entirely to use Messenger's defaults: 3 retries.)

A job that exhausts its retries (or throws an unrecoverable exception) is deleted
and recorded as a failed job, surfacing in the Laravel Cloud dashboard.

### Local development

The `cloud` transport is just a default, so an app-defined transport of the same
name wins. To process jobs inline locally without SQS, override it in `dev`:

```yaml
# config/packages/messenger.yaml
when@dev:
    framework:
        messenger:
            transports:
                cloud: 'sync://'
```

To turn the feature off entirely, set `laravel_cloud.queue.enabled: false`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

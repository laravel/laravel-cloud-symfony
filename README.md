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

That's it, locally and on Cloud alike. On Cloud, jobs are pushed to SQS and
received from the in-container agent; locally the transport falls back to
talking to SQS directly. Throughput, duration and failures show up in the
Laravel Cloud dashboard automatically.

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
queue throws rather than being silently ignored. FIFO queues don't support
per-message delivery delay, so any `DelayStamp` is ignored for them.

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

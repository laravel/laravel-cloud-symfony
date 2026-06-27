# laravel/symfony-on-cloud

Bring [Laravel Cloud](https://laravel.com/cloud) features to a [Symfony](https://symfony.com)
application.

[Managed Queues](#managed-queues) is the first feature. More Laravel Cloud
capabilities will be added over time, all configured under the same
`laravel_cloud` key.

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

> The package is not published to Packagist and ships no Symfony Flex recipe, so
> the bundle is registered manually. A Flex recipe could automate this (and the
> Messenger config below) in the future.

## Managed Queues

Run Laravel Cloud Managed Queues on top of
[Symfony Messenger](https://symfony.com/doc/current/messenger.html).

The bundle defines a ready-made `cloud` Messenger transport that always points
at your managed queue, so there's no transport to declare and no DSN to wire up
— just route your messages to it:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            '*': cloud
```

That's it, on Cloud and locally alike. The `cloud` transport resolves to
Laravel Cloud Managed Queues: inside a Laravel Cloud container jobs are pushed
to SQS and received from the in-container agent; locally it transparently falls
back to talking to SQS directly. Your jobs' throughput, duration and failures
show up in the Laravel Cloud dashboard automatically.

### Multiple queues

A Laravel Cloud environment can have several managed queues (for example
`default` and `critical`), each backed by its own SQS queue and its own isolated
workers. Reference a queue by its **name** — the transport assembles the full SQS
URL from the prefix and suffix Cloud injects — on both sides:

**Dispatching** to a specific queue uses `CloudQueueStamp`, the equivalent of
Laravel's `->onQueue('critical')`:

```php
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Symfony\Component\Messenger\MessageBusInterface;

$bus->dispatch(new ProcessReport($report), [new CloudQueueStamp('critical')]);
```

Without a stamp, a message goes to the environment's default managed queue. To
route by message type without stamping at every call site, add a middleware that
attaches the stamp based on the message class.

**Processing** a specific queue uses Messenger's `--queues` option, the
equivalent of Laravel's `queue:work --queue=critical`:

```bash
php bin/console messenger:consume cloud --queues=critical
```

On Laravel Cloud this is wired up for you: each managed queue gets its own
isolated workers running this command for its own queue. The worker only ever
processes the queue it was assigned.

For local development where you'd rather process jobs inline without SQS,
declare your own transport and route to it instead — the `cloud` transport is
just a default, so an app-defined transport of the same name wins:

```yaml
# config/packages/messenger.yaml
when@dev:
    framework:
        messenger:
            transports:
                cloud: 'sync://'
```

To turn the feature off, set `laravel_cloud.queue.enabled: false`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

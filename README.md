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

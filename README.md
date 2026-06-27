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

The bundle is auto-registered by Symfony Flex. If registering manually, add
`Laravel\Cloud\Symfony\LaravelCloudBundle` to `config/bundles.php`.

## Managed Queues

Run Laravel Cloud Managed Queues on top of
[Symfony Messenger](https://symfony.com/doc/current/messenger.html).

Define a Messenger transport with the `laravel-cloud://` DSN and route your
messages to it:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            cloud: 'laravel-cloud://managed-queue'
        routing:
            'App\Message\ProcessJob': cloud
```

That's it. Laravel Cloud injects the queue's connection details into your
environment automatically, so the DSN carries no connection config and the same
setup works both on Laravel Cloud and in local development. Your jobs' throughput,
duration and failures show up in the Laravel Cloud dashboard automatically.

To turn the feature off, set `laravel_cloud.queue.enabled: false`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

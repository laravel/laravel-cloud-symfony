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

Route your messages through a Messenger transport pointed at the managed queue.
Laravel Cloud injects `MESSENGER_TRANSPORT_DSN`, so the conventional `async`
transport works with no extra configuration:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            '*': async
```

On Laravel Cloud that's it: the platform injects
`MESSENGER_TRANSPORT_DSN=laravel-cloud://managed-queue` along with the queue's
connection details, so the same setup works on Cloud with zero configuration.
For local development, set the DSN yourself in `.env` (use `sync://` to process
jobs inline without SQS):

```dotenv
MESSENGER_TRANSPORT_DSN=laravel-cloud://managed-queue
```

Your jobs' throughput, duration and failures show up in the Laravel Cloud
dashboard automatically.

To turn the feature off, set `laravel_cloud.queue.enabled: false`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

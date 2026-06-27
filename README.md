# laravel/symfony-on-cloud

Bring [Laravel Cloud](https://laravel.com/cloud) features to a [Symfony](https://symfony.com)
application.

This is a **feature-based bundle**, not a single-purpose package. It is a thin
host that owns the shared Laravel Cloud platform primitives available inside a
Cloud container — the in-container **agent runtime socket** and the
**observability log socket** — and exposes them to a set of self-contained
*features*. Each feature plugs in its own configuration and services and builds
on those shared primitives.

**[Managed Queues](#managed-queues)** is the first feature. As more of the
Laravel Cloud platform becomes available to Symfony, each capability is added as
another feature behind the same `laravel_cloud.*` configuration — see
[Adding a feature](#adding-a-feature).

## Installation

```bash
composer require laravel/symfony-on-cloud
```

The bundle is auto-registered by Symfony Flex. If registering manually, add
`Laravel\Cloud\Symfony\LaravelCloudBundle` to `config/bundles.php`.

## Configuration

All configuration lives under the `laravel_cloud` key. The top level holds the
shared platform primitives; each feature has its own sub-section and can be
toggled with an `enabled` flag.

```yaml
# config/packages/laravel_cloud.yaml
laravel_cloud:
    # Shared platform primitives (sensible defaults; rarely set by hand).
    agent_socket: '/tmp/cloud-agent.sock'
    log_socket: 'unix:///tmp/cloud-init.sock'

    # Features.
    queue:
        enabled: true   # Managed Queues — on by default
```

These platform settings are overridable at runtime by the environment variables
Laravel Cloud injects:

| Environment variable          | Purpose                                  | Default                       |
| ----------------------------- | ---------------------------------------- | ----------------------------- |
| `LARAVEL_CLOUD_AGENT_SOCKET`  | Path to the cloud-agent runtime socket   | `/tmp/cloud-agent.sock`       |
| `LARAVEL_CLOUD_LOG_SOCKET`    | Address of the observability log socket  | `unix:///tmp/cloud-init.sock` |

---

## Managed Queues

Run [Laravel Cloud Managed Queues](https://laravel.com/cloud) on top of
[Symfony Messenger](https://symfony.com/doc/current/messenger.html).

Managed Queues are backed by SQS, but inside a Laravel Cloud container jobs are
**received from the in-container `cloud-agent` sidecar** over a unix socket
rather than polled from SQS directly. This feature provides a Messenger
transport that:

- **pushes** jobs straight to SQS, and
- **receives** them from the agent (`GET /next`), reporting each outcome back
  (`POST /result`) so the agent performs the actual SQS delete / visibility
  change.

When the agent socket is absent (e.g. local development) it transparently falls
back to receiving from SQS directly.

### Usage

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

The SQS coordinates are read from the `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG`
environment variable injected by Laravel Cloud, so the DSN itself carries no
connection detail.

| Environment variable                  | Purpose                                                                         |
| ------------------------------------- | ------------------------------------------------------------------------------- |
| `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG` | JSON describing the SQS connection (prefix, suffix, queue, region, credentials) |

That's all the configuration there is. Whether jobs are received via the
in-container agent (on Laravel Cloud) or polled directly from SQS (local
development) is detected automatically, so the same DSN works everywhere with no
extra options to set.

Disable the feature entirely with `laravel_cloud.queue.enabled: false`.

### Semantics

| Messenger    | Managed queue                                                    |
| ------------ | ---------------------------------------------------------------- |
| `send()`     | SQS `SendMessage` (DelayStamp → `DelaySeconds`, capped at 900s)  |
| `ack()`      | agent `POST /result {status: processed}` → SQS delete            |
| `reject()`   | same as `ack()` — see below                                      |

Symfony implements retries by re-sending a fresh copy of the message and then
**rejecting** the original, so a rejected message must leave the queue — exactly
how Symfony's own SQS transport behaves. The agent's `released` outcome (reset
visibility for SQS-native redelivery) is therefore not used under Messenger's
re-send retry model.

### Observability

So Managed Queues surface the same metrics in the Laravel Cloud dashboard as a
Laravel app, the feature emits the same observability events Laravel's framework
does — newline-delimited JSON written to the shared `LARAVEL_CLOUD_LOG_SOCKET`
(the wire protocol of `Illuminate\Foundation\Cloud\Events`). A Messenger event
subscriber maps the worker lifecycle onto those events:

| Cloud event              | When                                              | Mapped from                    |
| ------------------------ | ------------------------------------------------- | ------------------------------ |
| `queue` / `queued`       | a message is dispatched onto the managed queue    | `SendMessageToTransportsEvent` |
| `queue` / `started`      | a worker receives a managed-queue message         | `WorkerMessageReceivedEvent`   |
| `queue` / `processed`    | handling succeeds (carries `duration_ms`)         | `WorkerMessageHandledEvent`    |
| `queue` / `released`     | handling fails but the message **will** retry     | `WorkerMessageFailedEvent`     |
| `queue` / `failed`       | handling fails terminally                         | `WorkerMessageFailedEvent`     |
| `failed_job`             | terminal failure — full payload + exception       | `WorkerMessageFailedEvent`     |

The failure handler runs after Messenger's retry listener so `released` vs.
`failed` reflects the final retry decision. Emission is best-effort: a socket
that is absent or unwritable (e.g. local development) is silently ignored and
never affects job processing.

> **Note — `failed_job` size cap (temporary workaround).** Laravel Cloud's log
> collector (Fluent Bit) does not currently reassemble containerd's 16 KiB CRI
> partial log lines, so a `failed_job` event larger than 16 KiB arrives as
> truncated JSON and is misrouted away from the failed-jobs topic. To survive
> this, the `payload` and `exception` fields are trimmed (see the `MAX_*`
> constants in `QueueEventSubscriber`). The durable fix is platform-side —
> adding `multiline.parser cri` to the collector's tail input — after which the
> caps can be relaxed.

---

## Architecture

```
LaravelCloudBundle            thin host: owns the shared platform primitives,
│                             registers each enabled feature
├── PlatformContext           agent socket · log socket · Events emitter
├── Observability\Events      shared log-socket event emitter (any feature)
│
└── features (CloudFeature[])
    └── Queue\QueueFeature     Managed Queues — Messenger transport, SQS client,
                               agent client, queue metrics subscriber
```

The bundle never special-cases any one feature. It wires the shared primitives
once, then loops over its features — each declaring its own `laravel_cloud.<name>`
config and registering its own services through the `CloudFeature` contract. A
new Laravel Cloud capability is added by implementing `CloudFeature` and listing
it in `LaravelCloudBundle::features()`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

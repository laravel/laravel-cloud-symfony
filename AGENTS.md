# AGENTS.md

Orientation for AI agents (and humans) working in this repository. For end-user
usage docs see `README.md`; this file is about how the codebase is built and the
conventions to keep when changing it.

## What this package is

`laravel/symfony-on-cloud` is a **Symfony bundle that brings Laravel Cloud
features to a Symfony app**. It is deliberately *feature-based*: the bundle itself
is a thin host that owns a few shared platform primitives and delegates each
capability to a self-contained "feature". Everything lives under a single
`laravel_cloud` config key.

The **only feature today is Managed Queues** — running Laravel Cloud's managed
SQS queues on top of Symfony Messenger. More Laravel Cloud capabilities are meant
to slot in beside it under the same pattern.

- PHP `>= 8.2`, Symfony `^7 || ^8`, `aws/aws-sdk-php` `^3`, Guzzle `^7`.
- PSR-4: `Laravel\Cloud\Symfony\` → `src/`, `Laravel\Cloud\Symfony\Tests\` → `tests/`.
- No Packagist release / Flex recipe yet; the bundle is registered manually.

## The bundle / feature architecture

```
LaravelCloudBundle (AbstractBundle)         ← thin host
  ├─ shared platform primitives (PlatformContext)
  │    ├─ agent socket   (LARAVEL_CLOUD_AGENT_SOCKET, default /tmp/cloud-agent.sock)
  │    └─ log socket     (LARAVEL_CLOUD_LOG_SOCKET) → Observability\Events
  └─ features(): list<CloudFeature>
       └─ QueueFeature   (the Managed Queues capability)
```

- **`CloudFeature`** (`src/CloudFeature.php`): every feature implements `name()`,
  `configure(NodeBuilder)` (contributes its `laravel_cloud.<name>` config subtree)
  and `register(config, container, builder, PlatformContext)` (wires its services).
- **`PrependsConfig`** (`src/PrependsConfig.php`): optional second interface for a
  feature that must *prepend* framework config before it's merged (QueueFeature
  uses it to ship the ready-made `cloud` Messenger transport).
- **`LaravelCloudBundle`** (`src/LaravelCloudBundle.php`): registers the shared
  primitives, then loops `features()` for `configure` / `prepend` / `register`. A
  feature is enabled unless its slice sets `enabled: false`
  (`canBeDisabled()`); the prepend and load passes share one `isFeatureEnabled()`
  check so they never drift.
- **`PlatformContext`** (`src/PlatformContext.php`): hands features DI references
  to the shared agent-socket param, log-socket param, and the `Events` emitter.
- **`Observability\Events`** (`src/Observability/Events.php`): writes
  newline-delimited JSON `_cloud_event` records to the Cloud log unix socket (the
  same wire protocol Laravel uses). Lazily connects, reconnects, and prefixes
  socket metadata. This is the single channel for dashboard metrics — features
  never open their own socket.

**To add a feature:** implement `CloudFeature` (and `PrependsConfig` if needed),
add it to `LaravelCloudBundle::features()`, and build on `PlatformContext` for the
shared socket/emitter. Don't add bundle-level plumbing for one feature.

## Managed Queues — how it works

Wired by `QueueFeature` (`src/Queue/QueueFeature.php`). The moving parts:

| Class | Role |
|---|---|
| `Messenger\CloudQueueTransport` | The Messenger transport: send / receive / ack / reject. |
| `Messenger\CloudQueueTransportFactory` | Owns the `laravel-cloud://` DSN scheme (host `managed-queue`/`managed-queues`). Auto-detects agent vs direct-SQS. |
| `ManagedQueueConfig` | Parses `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG`; builds queue URLs (Laravel-style suffixing, FIFO-aware); `isFifo()`, `agentAvailable()`. |
| `Sqs\SqsClientFactory` | Builds the AWS `SqsClient` from the parsed config (region, ECS credentials). |
| `Agent\AgentClient` | Talks to the in-container cloud-agent over its unix socket. |
| `QueueEventSubscriber` | Emits queue lifecycle + failed-job events to the dashboard via `Events`. |

### The two receive paths (this is the core mental model)

Pushing **always** goes straight to SQS. Receiving depends on environment:

- **On Laravel Cloud**: an in-container **cloud-agent sidecar** long-polls SQS and
  hands messages over its unix socket (`AgentClient::next()` → `GET /next`). The
  worker reports the outcome back (`POST /result`) and the *agent* performs the
  SQS delete / visibility change. The app pod never touches SQS on the receive
  side. Detected by the agent socket existing on disk (`agentAvailable()`).
- **Locally / no agent**: the transport receives straight from SQS
  (`ReceiveMessage`) and performs deletes / visibility changes itself.

`CloudQueueTransport` branches on a `useAgent` flag throughout (`ack`, `reject`,
`release`, `get`). The agent outcomes are **`processed`** (delete) and
**`released`** (visibility reset). There is no `failed` agent outcome — terminal
failures are reported as `processed` (the message is deleted) and the *failure*
signal is emitted separately as a log event (see Observability).

### Message payload shape

`encode()` wraps the Messenger envelope as self-contained JSON:
`{ uuid, displayName, body (base64), headers }`. `uuid`/`displayName` mirror
Laravel's queue payload so the dashboard can name a failed job; `decode()` only
reads `body`/`headers`, so the extra keys round-trip harmlessly.

### Targeting queues, FIFO, fair queues, delays

These are user-facing and documented in `README.md`; the mechanics live in
`send()` and the stamps. Summary for code work:

- **`CloudQueueStamp`** — picks the target managed queue by name (`->onQueue()`).
- **`CloudFifoStamp`** (`.fifo` queues only) — sets MessageGroupId (ordering scope)
  + MessageDeduplicationId. Rejected on a standard queue.
- **`CloudMessageGroupStamp`** (standard queues only) — a fair-queue *tenant key*
  (no ordering/dedupe). Rejected on a `.fifo` queue.
- **Delays**: a `DelayStamp` maps to SQS `DelaySeconds`, hard-capped at 15 min —
  over that the transport **throws** rather than silently under-delaying. FIFO
  queues reject per-message delays entirely.

Loud failure over silent surprise is the rule here: a delay/stamp the queue model
can't honour throws a `TransportException`, it isn't quietly dropped.

### Retries

Retries are **Symfony Messenger-native, executed as SQS visibility resets** — no
re-send, no duplicate message. The design:

1. **Counter bridge.** SQS redelivers the *original* message, which carries no
   `RedeliveryStamp`, so Messenger's stock counter would read 0 forever.
   `decode()` reconstructs `new RedeliveryStamp($receiveCount - 1)` from SQS's
   `ApproximateReceiveCount`, so the **stock `MultiplierRetryStrategy` counts and
   backs off correctly** and the standard config is honoured.
2. **Decision** stays with Symfony's `SendFailedMessageForRetryListener` (incl.
   `UnrecoverableExceptionInterface` handling). It sets `willRetry()` and, on a
   retry, re-sends.
3. **Execution.** `send()` intercepts that re-send (it carries a `RedeliveryStamp`)
   and turns it into a **visibility reset** — the agent's `released` outcome, or
   `ChangeMessageVisibility` directly — using the listener's computed `DelayStamp`
   backoff, **clamped to SQS's 12-hour ceiling**. `reject()` then no-ops for a
   retry (the release already happened) and **deletes** terminal failures.

Config is the standard Messenger knob — no bespoke config:

```yaml
framework:
    messenger:
        transports:
            cloud:
                retry_strategy:
                    max_retries: 5   # → 6 total deliveries
                    delay: 1000
                    multiplier: 2
                    max_delay: 0     # SQS still hard-caps the visibility reset at 12h
```

Because the counter bridge lives in `decode()` (which runs for *every* message on
the transport regardless of transport name), a user-defined transport pointed at
`laravel-cloud://` gets correct retry behaviour automatically — no special wiring.

### Observability events

`QueueEventSubscriber` listens to the Messenger worker lifecycle and emits Cloud
`_cloud_event` records via `Events`:

- `queued` (on send) · `started` (received) · `processed` (handled) ·
  `released` (failed but will retry) · `failed` (terminal) · `failed_job`
  (terminal: full payload + exception + attempt count).
- It recognises *our* messages by the `CloudQueueReceivedStamp`, not the
  transport name.
- It reads **`willRetry()`** to choose `released` vs `failed` — and runs at
  **priority -100**, after Symfony's retry listener (priority 100) has set that
  flag. Don't reorder this.
- `failed_job` attempts come from `ApproximateReceiveCount` (mirroring Laravel's
  `SqsJob::attempts()`), not the synthesized `RedeliveryStamp`.
- There is a **temporary trimming workaround** (`MAX_EXCEPTION_BYTES` /
  `MAX_PAYLOAD_BODY_BYTES`) keeping `failed_job` under a 16 KiB CRI log-line limit
  in the platform's log collector. It's documented in-file as removable once the
  collector reassembles partial lines — don't treat it as permanent.

### Stamps (all in `src/Queue/Messenger/`)

| Stamp | Sendable? | Purpose |
|---|---|---|
| `CloudQueueStamp` | yes | Target managed queue by name. |
| `CloudFifoStamp` | no | FIFO group + dedupe IDs. |
| `CloudMessageGroupStamp` | yes | Fair-queue tenant key (standard queues). |
| `CloudQueueReceivedStamp` | no | SQS coords + `approximateReceiveCount` + raw body, threaded from receive to ack/reject/observability. |
| `CloudProcessingStartedStamp` | no | Start time, for duration metrics. |

In-worker decisions use **non-sendable** stamps so they never serialize onto a
message.

## Local development & disabling

- The `cloud` transport is *prepended*, so an app-defined transport of the same
  name overrides it (e.g. `cloud: 'sync://'` in `dev`).
- With no agent socket present, everything talks to SQS directly — so it runs
  locally against a real/queue-compatible endpoint without the sidecar.
- `laravel_cloud.queue.enabled: false` removes the transport, SQS client and
  metrics entirely.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Tests are unit + integration with **real fakes, not mocks of our own code**:

- **SQS**: AWS SDK `MockHandler` (`CloudQueueTransportTest`,
  `CloudRetryIntegrationTest`) — assert on the actual SQS command names/args.
- **Agent**: Guzzle `MockHandler`, plus a real unix-socket agent stub
  (`tests/Fixtures/agent-server.php`, driven by `AgentSocketIntegrationTest`).
- **Observability**: a real unix socket server captures the emitted JSON lines
  (`QueueEventSubscriberTest`).
- **Retry**: `CloudRetryIntegrationTest` and the end-to-end test in
  `QueueEventSubscriberTest` drive the **real** Symfony `SendFailedMessageForRetryListener`
  + stock `MultiplierRetryStrategy` against the transport — proving the native
  retry decision, the visibility-reset execution, the 12h clamp, and that
  `failed_job` is still emitted on terminal failure.

When changing retry/observability, prefer adding to these integration tests
(real listener + real strategy) over asserting internal state.

## Conventions to keep

- **Heavy explanatory comments.** Non-obvious code carries a comment explaining
  *why* (SQS limits, Laravel-parity choices, ordering constraints). Match that
  density; explain the reasoning, not the mechanics.
- **Mirror Laravel's SQS semantics** where there's a choice (payload shape,
  attempt counting, FIFO defaults, `$job->release()`).
- **Fail loudly** on an unsatisfiable request (delay over the SQS cap, a stamp on
  the wrong queue model) rather than silently degrading.
- **One observability channel** (`Events` over the log socket) and **one config
  key** (`laravel_cloud`).
- Keep features self-contained; reach for `PlatformContext`, not new bundle-level
  globals.
```

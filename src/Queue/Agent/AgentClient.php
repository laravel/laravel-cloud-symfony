<?php

namespace Laravel\Cloud\Symfony\Queue\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * Talks to the in-container cloud-agent over its unix runtime socket.
 *
 * The agent long-polls a single SQS queue on behalf of one consumer, holds the
 * received message (heartbeating its visibility), and hands it over via
 * GET /next. The consumer reports the terminal outcome via POST /result, at
 * which point the agent performs the actual SQS delete/visibility change. This
 * pod never touches SQS on the receive path.
 */
final class AgentClient
{
    public function __construct(
        private readonly ManagedQueueConfig $config,
        private ?ClientInterface $http = null,
    ) {
    }

    /**
     * Long-poll the agent for the next message.
     *
     * Returns the decoded payload (messageId, receiptHandle, body, attributes,
     * queueUrl), or null when the agent has nothing (HTTP 204). Anything else —
     * an unreachable socket, an unexpected status, or a non-array body — means
     * the agent cannot serve work and raises a Messenger TransportException. The
     * Worker doesn't catch this around get(), so the consumer exits and the pod
     * restarts (a wedged agent only recovers on restart).
     *
     * @return array<string, mixed>|null
     *
     * @throws TransportException
     */
    public function next(): ?array
    {
        try {
            $response = $this->client()->request('GET', '/next', [
                // Outlast the agent's ~55s poll cycle so a 204 is a deliberate
                // "nothing yet", never our own timeout cutting a poll short.
                'timeout' => 65,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new TransportException('The Laravel Cloud agent runtime socket is unreachable.', previous: $e);
        } catch (GuzzleException $e) {
            throw new TransportException('The Laravel Cloud agent request failed.', previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status === 204) {
            return null;
        }

        if ($status !== 200) {
            throw new TransportException("The Laravel Cloud agent returned HTTP {$status} from GET /next.");
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw new TransportException('The Laravel Cloud agent returned a non-array body from GET /next.');
        }

        return $data;
    }

    /**
     * Report a message's terminal outcome to the agent so it can stop
     * heartbeating and perform the SQS operation. "processed" deletes the
     * message; "released" resets its visibility (after $delay seconds) so SQS
     * redelivers it.
     *
     * Transient socket hiccups are retried. An unreachable socket or a server
     * error means the agent itself is wedged and raises a Messenger
     * TransportException (exit + restart the pod). A client-error rejection —
     * the agent is the authority on a valid outcome for this one message —
     * surfaces as a RuntimeException so the consumer reports it and moves on.
     * The job is never lost: a crashed agent stops heartbeating, so SQS
     * redelivers once the visibility timeout lapses.
     *
     * @throws TransportException
     */
    public function report(string $messageId, ?string $receiptHandle, string $status, ?int $delay = null): void
    {
        $payload = array_filter([
            'messageId' => $messageId,
            'receiptHandle' => $receiptHandle,
            'status' => $status,
            'delay' => $delay,
        ], static fn ($value) => $value !== null);

        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                $response = $this->client()->request('POST', '/result', [
                    'timeout' => 10,
                    'http_errors' => false,
                    'json' => $payload,
                ]);
            } catch (ConnectException $e) {
                if ($attempts < 3) {
                    usleep(100_000);
                    continue;
                }

                throw new TransportException('The Laravel Cloud agent runtime socket is unreachable.', previous: $e);
            } catch (GuzzleException $e) {
                throw new TransportException('The Laravel Cloud agent request failed.', previous: $e);
            }

            $code = $response->getStatusCode();

            if ($code >= 500) {
                throw new TransportException("The Laravel Cloud agent returned HTTP {$code} from POST /result.");
            }

            if ($code >= 400) {
                throw new RuntimeException("The Laravel Cloud agent rejected the result with HTTP {$code}.");
            }

            return;
        }
    }

    private function client(): ClientInterface
    {
        return $this->http ??= new Client([
            'base_uri' => 'http://localhost',
            'curl' => [
                // Dial the socket the injected agent config points at — the same
                // path the transport's agent-availability decision is based on.
                CURLOPT_UNIX_SOCKET_PATH => $this->config->agentSocket,
            ],
        ]);
    }
}

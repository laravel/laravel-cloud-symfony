<?php

namespace Laravel\Cloud\Symfony\Agent;

use RuntimeException;

/**
 * Thrown when the in-container cloud-agent's runtime socket cannot serve work —
 * an unreachable socket, an unexpected status, or a malformed body. A crashed or
 * wedged agent can only be recovered by restarting the pod, so the consumer
 * should treat this as fatal and exit rather than spin re-polling a broken agent.
 */
class AgentUnreachableException extends RuntimeException
{
}

<?php

/**
 * A minimal HTTP/1.1 server over a unix socket, standing in for the in-container
 * cloud-agent so the AgentClient's real Guzzle-over-unix-socket path can be
 * exercised in tests.
 *
 * Usage: php agent-server.php <socket-path> <max-requests>
 *
 * GET /next   -> 200 with a canned job payload
 * POST /result -> 200 (empty)
 * After <max-requests> handled requests it exits.
 */
$socketPath = $argv[1] ?? null;
$maxRequests = (int) ($argv[2] ?? 2);

if ($socketPath === null) {
    fwrite(STDERR, "missing socket path\n");
    exit(1);
}

@unlink($socketPath);

$server = @stream_socket_server('unix://'.$socketPath, $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "cannot bind socket: {$errstr}\n");
    exit(1);
}

$handled = 0;

while ($handled < $maxRequests) {
    $conn = @stream_socket_accept($server, 5);

    if ($conn === false) {
        break;
    }

    // Read the request head; enough to learn the method and path.
    $request = '';
    while (! str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 4096);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }

    $isNext = str_starts_with($request, 'GET /next');

    $body = $isNext
        ? json_encode([
            'messageId' => 'integration-message',
            'receiptHandle' => 'integration-receipt',
            'body' => 'integration-body',
            'queueUrl' => 'https://sqs/integration',
        ])
        : '';

    $response = "HTTP/1.1 200 OK\r\n"
        ."Content-Type: application/json\r\n"
        ."Content-Length: ".strlen($body)."\r\n"
        ."Connection: close\r\n"
        ."\r\n"
        .$body;

    fwrite($conn, $response);
    fclose($conn);
    $handled++;
}

fclose($server);
@unlink($socketPath);

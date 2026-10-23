<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/TestCase.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

/**
 * Build a Guzzle client that returns the given responses in order. Each entry
 * is a [statusCode, payloadArray] pair served as application/json.
 */
function mockHttp(array $responses): Client
{
    $queue = [];

    foreach ($responses as [$status, $payload]) {
        $queue[] = new Response($status, ['Content-Type' => 'application/json'], json_encode($payload));
    }

    return new Client(['handler' => new MockHandler($queue)]);
}

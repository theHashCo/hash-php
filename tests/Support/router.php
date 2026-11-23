<?php

declare(strict_types=1);

// The router of `php -S`, serving the mock Hash API. Started by MockServer.

use Hash\Tests\Support\MockApi;
use Hash\Tests\Support\State;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}

$api = new MockApi(new State((string) getenv('HASH_MOCK_STATE')));
$answer = $api->handle((string) $_SERVER['REQUEST_METHOD'], (string) $_SERVER['REQUEST_URI'], $headers, (string) file_get_contents('php://input'));

if ($answer['delay'] > 0) {
    usleep((int) ($answer['delay'] * 1_000_000));
}
http_response_code($answer['status']);
foreach ($answer['headers'] as $name => $value) {
    header("$name: $value");
}
echo $answer['body'];

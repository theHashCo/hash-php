<?php

declare(strict_types=1);

use Hash\Tests\Support\MockServer;

require dirname(__DIR__) . '/vendor/autoload.php';

register_shutdown_function([MockServer::class, 'shutdown']);

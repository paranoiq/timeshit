<?php declare(strict_types=1);

use Timeshit\Server;

require __DIR__ . '/vendor/autoload.php';

Server::forRoot(__DIR__)->run();

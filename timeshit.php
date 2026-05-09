<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Timeshit\App;

exit((new App(__DIR__))->run($argv));

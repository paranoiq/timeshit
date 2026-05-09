<?php declare(strict_types=1);

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
	echo "Composer:\n";
    passthru('composer install --working-dir=' . escapeshellarg(__DIR__), $code);
	if ($code !== 0) {
		exit($code);
	}
    echo "\n\n";
}

require __DIR__ . '/vendor/autoload.php';

use Timeshit\App;

exit((new App(__DIR__))->run($argv));

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
use Timeshit\Config;
use Timeshit\Configurator;
use Timeshit\Util\Ansi;
use Timeshit\Util\StdIo;

$rootDir = __DIR__;
$io = new StdIo();
$arg = $argv[1] ?? null;

if ($arg === null || $arg === '' || $arg === '-h' || $arg === '--help') {
    App::printHelp($io);
    exit(0);
}

if (!is_file($rootDir . '/config/secrets.neon')) {
    try {
        (new Configurator($rootDir, $io))->run();
    } catch (Throwable $e) {
        $io->err(Ansi::red("Error: " . $e->getMessage()) . "\n");
        exit(1);
    }
    exit(0);
}

try {
    $config = Config::load($rootDir);
} catch (Throwable $e) {
    $io->err(Ansi::red("Error: " . $e->getMessage()) . "\n");
    exit(1);
}

exit(App::forRoot($rootDir, $config, $io)->run($argv));
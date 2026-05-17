<?php declare(strict_types=1);

use Timeshit\App;
use Timeshit\Config;
use Timeshit\Help;
use Timeshit\Local\FileRecordStore;
use Timeshit\Resolver;
use Timeshit\Util\BufferedIo;

require __DIR__ . '/vendor/autoload.php';

set_time_limit(0);

$rootDir = __DIR__;
$config = Config::load($rootDir);
date_default_timezone_set($config->timezone);

$pidFile = $rootDir . '/data/server.pid';
$heartbeatFile = $rootDir . '/data/heartbeat';
$recordsFile = $rootDir . '/data/records.neon';
$archiveFile = $rootDir . '/data/archive.neon';

$bindAddr = "tcp://127.0.0.1:{$config->port}";
$server = stream_socket_server($bindAddr, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Cannot bind to {$bindAddr}: {$errstr} ({$errno})\n");
    exit(1);
}

$myPid = (string) getmypid();
file_put_contents($pidFile, $myPid);
register_shutdown_function(static function () use ($pidFile, $myPid): void {
    if (is_file($pidFile) && trim((string) file_get_contents($pidFile)) === $myPid) {
        unlink($pidFile);
    }
});

$store = new FileRecordStore($recordsFile, $archiveFile);

/** Each loop iteration sleeps ~10ms; 3000 cycles ≈ 30s. */
$cyclesPerHeartbeat = 3000;
/** Gap between two heartbeats that we treat as the PC having been off (hibernate / suspend / crash). */
$hibernationThreshold = 10 * 60;
$cycle = 0;
$startedAt = time();

while (true) {
    $client = @stream_socket_accept($server, 0);
    if ($client !== false) {
        handleClient($client, $rootDir, $config, $startedAt);
    }
    $cycle++;
    if ($cycle >= $cyclesPerHeartbeat) {
        $cycle = 0;
        try {
            $now = new DateTimeImmutable();
            $nowStr = $now->format('Y-m-d H:i');
            $prevStr = is_file($heartbeatFile)
                ? trim((string) file_get_contents($heartbeatFile))
                : '';
            if ($prevStr !== '') {
                $prev = DateTimeImmutable::createFromFormat('Y-m-d H:i', $prevStr);
                if ($prev !== false && ($now->getTimestamp() - $prev->getTimestamp()) > $hibernationThreshold) {
                    $store->endOpen($prevStr, 'heartbeat', null);
                }
            }
            file_put_contents($heartbeatFile, $nowStr);
        } catch (Throwable $e) {
            fwrite(STDERR, "heartbeat error: " . $e->getMessage() . "\n");
        }
    }
    usleep(10000);
}

/** @param resource $client */
function handleClient($client, string $rootDir, Config $config, int $startedAt): void
{
    stream_set_timeout($client, 1);
    $raw = (string) fread($client, 65536);
    if (preg_match('#^(GET|POST|PUT|DELETE|PATCH|OPTIONS|HEAD) #', $raw) === 1) {
        handleHttp($client, $rootDir, $config, $raw);
    } else {
        handlePlain($client, $rootDir, $config, $startedAt, $raw);
    }
    fclose($client);
}

/** @param resource $client */
function handlePlain($client, string $rootDir, Config $config, int $startedAt, string $raw): void
{
    $command = trim($raw);
    if ($command === 'uptime') {
        fwrite($client, (string) (time() - $startedAt));

        return;
    }
    if ($command !== '') {
        dispatch($rootDir, $config, $command);
    }
    fwrite($client, 'ok');
}

/** @param resource $client */
function handleHttp($client, string $rootDir, Config $config, string $raw): void
{
    $headerEnd = strpos($raw, "\r\n\r\n");
    if ($headerEnd === false) {
        sendHttp($client, 'ok');

        return;
    }
    $headerSection = substr($raw, 0, $headerEnd);
    $body = substr($raw, $headerEnd + 4);
    $lines = explode("\r\n", $headerSection);
    $method = strtoupper((string) (explode(' ', $lines[0] ?? '', 2)[0] ?? ''));
    $contentLength = 0;
    for ($i = 1, $n = count($lines); $i < $n; $i++) {
        if (stripos($lines[$i], 'content-length:') === 0) {
            $contentLength = max(0, (int) trim(substr($lines[$i], 15)));
        }
    }
    while (strlen($body) < $contentLength) {
        $more = fread($client, $contentLength - strlen($body));
        if ($more === false || $more === '') {
            break;
        }
        $body .= $more;
    }
    if ($method !== 'OPTIONS') {
        $command = trim($body);
        if ($command !== '') {
            dispatch($rootDir, $config, $command);
        }
    }
    sendHttp($client, 'ok');
}

/** @param resource $client */
function sendHttp($client, string $body): void
{
    $len = strlen($body);
    $response = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: text/plain\r\n"
        . "Content-Length: {$len}\r\n"
        . "Access-Control-Allow-Origin: *\r\n"
        . "Connection: close\r\n\r\n"
        . $body;
    fwrite($client, $response);
}

function dispatch(string $rootDir, Config $config, string $line): void
{
    $tokens = str_getcsv($line, ' ', '"', '\\');
    $tokens = array_values(array_filter($tokens, static fn(?string $t): bool => $t !== null && $t !== ''));
    if ($tokens === []) {
        return;
    }
    if (!isAction($tokens[0], $config)) {
        fwrite(STDERR, "dispatch: refused non-action command '{$tokens[0]}'\n");

        return;
    }
    try {
        App::forRoot($rootDir, $config, new BufferedIo())->run(['server.php', ...$tokens]);
    } catch (Throwable $e) {
        fwrite(STDERR, "dispatch error: " . $e->getMessage() . "\n");
    }
}

/**
 * Resolves the first token the same way the CLI dispatcher does (case-insensitive
 * exact match, then unique-prefix match, alias translation), then returns true
 * only when the canonical name is in `Help::ACTION_COMMAND_NAMES` or refers to a
 * custom command (custom commands' parents are always action builtins).
 */
function isAction(string $input, Config $config): bool
{
    $names = Help::BUILTIN_COMMAND_NAMES;
    foreach ($config->customCommands as $c) {
        $names[] = $c->name;
    }
    foreach ($config->commandAliases as $alias => $_canonical) {
        $names[] = $alias;
    }
    try {
        $resolved = Resolver::matchCommand($input, $names);
    } catch (RuntimeException) {
        return false;
    }
    if ($resolved === null) {
        return false;
    }
    if (isset($config->commandAliases[$resolved])) {
        $resolved = $config->commandAliases[$resolved];
    }
    foreach ($config->customCommands as $c) {
        if ($c->name === $resolved) {
            return true;
        }
    }

    return in_array($resolved, Help::ACTION_COMMAND_NAMES, true);
}
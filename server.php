<?php declare(strict_types=1);

use Timeshit\Config;
use Timeshit\Local\FileRecordStore;

require __DIR__ . '/vendor/autoload.php';

set_time_limit(0);
date_default_timezone_set(Config::timezone(__DIR__));

$rootDir = __DIR__;
$pidFile = $rootDir . '/data/server.pid';
$heartbeatFile = $rootDir . '/data/heartbeat';
$recordsFile = $rootDir . '/data/records.neon';
$archiveFile = $rootDir . '/data/archive.neon';

$server = stream_socket_server('tcp://127.0.0.1:1885', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Cannot bind to port 1885: {$errstr} ({$errno})\n");
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
        $request = trim((string) fread($client, 65536));
        $response = $request === 'uptime' ? (string) (time() - $startedAt) : 'ok';
        fwrite($client, $response);
        fclose($client);
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
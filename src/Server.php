<?php declare(strict_types=1);

namespace Timeshit;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Timeshit\Local\FileRecordStore;
use Timeshit\Util\BufferedIo;

use function array_filter;
use function array_values;
use function count;
use function date_default_timezone_set;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function fwrite;
use function getmypid;
use function in_array;
use function is_file;
use function max;
use function preg_match;
use function register_shutdown_function;
use function set_time_limit;
use function str_getcsv;
use function strlen;
use function stripos;
use function strpos;
use function strtoupper;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_server;
use function substr;
use function time;
use function trim;
use function unlink;
use function usleep;

use const STDERR;

final class Server
{
    /** Each loop iteration sleeps ~10ms; 3000 cycles ≈ 30s between heartbeats. */
    private const HEARTBEAT_CYCLES = 3000;

    /** Gap between two heartbeats that we treat as the PC having been off (hibernate / suspend / crash). */
    private const HIBERNATION_THRESHOLD_SEC = 10 * 60;

    private const LOOP_SLEEP_USEC = 10_000;
    private const ACCEPT_BUFFER_BYTES = 65_536;
    private const CLIENT_READ_TIMEOUT_SEC = 1;

    private readonly string $pidFile;
    private readonly string $heartbeatFile;
    private readonly FileRecordStore $store;
    private readonly int $startedAt;

    public function __construct(
        private readonly string $rootDir,
        private readonly Config $config,
    ) {
        $this->pidFile = $rootDir . '/data/server.pid';
        $this->heartbeatFile = $rootDir . '/data/heartbeat';
        $this->store = new FileRecordStore(
            $rootDir . '/data/records.neon',
            $rootDir . '/data/archive.neon',
        );
        $this->startedAt = time();
    }

    public static function forRoot(string $rootDir): self
    {
        $config = Config::load($rootDir);
        date_default_timezone_set($config->timezone);

        return new self($rootDir, $config);
    }

    public function run(): never
    {
        set_time_limit(0);
        $listener = $this->bind();
        $this->writePidFile();

        $cycle = 0;
        while (true) {
            $client = @stream_socket_accept($listener, 0);
            if ($client !== false) {
                $this->handleClient($client);
            }
            $cycle++;
            if ($cycle >= self::HEARTBEAT_CYCLES) {
                $cycle = 0;
                $this->heartbeat();
            }
            usleep(self::LOOP_SLEEP_USEC);
        }
    }

    /** @return resource */
    private function bind()
    {
        $bindAddr = "tcp://127.0.0.1:{$this->config->port}";
        $listener = stream_socket_server($bindAddr, $errno, $errstr);
        if ($listener === false) {
            fwrite(STDERR, "Cannot bind to {$bindAddr}: {$errstr} ({$errno})\n");
            exit(1);
        }

        return $listener;
    }

    private function writePidFile(): void
    {
        $myPid = (string) getmypid();
        $pidFile = $this->pidFile;
        file_put_contents($pidFile, $myPid);
        register_shutdown_function(static function () use ($pidFile, $myPid): void {
            if (is_file($pidFile) && trim((string) file_get_contents($pidFile)) === $myPid) {
                unlink($pidFile);
            }
        });
    }

    private function heartbeat(): void
    {
        try {
            $now = new DateTimeImmutable();
            $nowStr = $now->format('Y-m-d H:i');
            $prevStr = is_file($this->heartbeatFile)
                ? trim((string) file_get_contents($this->heartbeatFile))
                : '';
            if ($prevStr !== '') {
                $prev = DateTimeImmutable::createFromFormat('Y-m-d H:i', $prevStr);
                if ($prev !== false && ($now->getTimestamp() - $prev->getTimestamp()) > self::HIBERNATION_THRESHOLD_SEC) {
                    $this->store->endOpen($prevStr, 'heartbeat', null);
                }
            }
            file_put_contents($this->heartbeatFile, $nowStr);
        } catch (Throwable $e) {
            fwrite(STDERR, "heartbeat error: {$e->getMessage()}\n");
        }
    }

    /** @param resource $client */
    private function handleClient($client): void
    {
        stream_set_timeout($client, self::CLIENT_READ_TIMEOUT_SEC);
        $raw = (string) fread($client, self::ACCEPT_BUFFER_BYTES);
        if (preg_match('#^(GET|POST|PUT|DELETE|PATCH|OPTIONS|HEAD) #', $raw) === 1) {
            $this->handleHttp($client, $raw);
        } else {
            $this->handlePlain($client, $raw);
        }
        fclose($client);
    }

    /** @param resource $client */
    private function handlePlain($client, string $raw): void
    {
        $command = trim($raw);
        if ($command === 'uptime') {
            fwrite($client, (string) (time() - $this->startedAt));

            return;
        }
        if ($command !== '') {
            $this->dispatch($command);
        }
        fwrite($client, 'ok');
    }

    /** @param resource $client */
    private function handleHttp($client, string $raw): void
    {
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false) {
            $this->sendHttp($client, 'ok');

            return;
        }
        $headerSection = substr($raw, 0, $headerEnd);
        $body = substr($raw, $headerEnd + 4);
        $lines = explode("\r\n", $headerSection);
        $method = strtoupper(explode(' ', $lines[0], 2)[0]);
        $contentLength = 0;
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (stripos($lines[$i], 'content-length:') === 0) {
                $contentLength = max(0, (int) trim(substr($lines[$i], 15)));
            }
        }
        while (true) {
            $remaining = $contentLength - strlen($body);
            if ($remaining < 1) {
                break;
            }
            $more = fread($client, $remaining);
            if ($more === false || $more === '') {
                break;
            }
            $body .= $more;
        }
        if ($method !== 'OPTIONS') {
            $command = trim($body);
            if ($command !== '') {
                $this->dispatch($command);
            }
        }
        $this->sendHttp($client, 'ok');
    }

    /** @param resource $client */
    private function sendHttp($client, string $body): void
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

    private function dispatch(string $line): void
    {
        $tokens = str_getcsv($line, ' ', '"', '\\');
        $tokens = array_values(array_filter($tokens, static fn(?string $t): bool => $t !== null && $t !== ''));
        if ($tokens === []) {
            return;
        }
        if (!$this->isAction($tokens[0])) {
            fwrite(STDERR, "dispatch: refused non-action command '{$tokens[0]}'\n");

            return;
        }
        try {
            App::forRoot($this->rootDir, $this->config, new BufferedIo())->run(['server.php', ...$tokens]);
        } catch (Throwable $e) {
            fwrite(STDERR, "dispatch error: {$e->getMessage()}\n");
        }
    }

    /**
     * Resolves the first token the same way the CLI dispatcher does (case-insensitive
     * exact match, then unique-prefix match, alias translation), then returns true
     * only when the canonical name is in `Help::ACTION_COMMAND_NAMES` or refers to a
     * custom command (custom commands' parents are always action builtins).
     */
    private function isAction(string $input): bool
    {
        $names = Help::BUILTIN_COMMAND_NAMES;
        foreach ($this->config->customCommands as $custom) {
            $names[] = $custom->name;
        }
        foreach ($this->config->commandAliases as $alias => $_canonical) {
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
        if (isset($this->config->commandAliases[$resolved])) {
            $resolved = $this->config->commandAliases[$resolved];
        }
        foreach ($this->config->customCommands as $custom) {
            if ($custom->name === $resolved) {
                return true;
            }
        }

        return in_array($resolved, Help::ACTION_COMMAND_NAMES, true);
    }
}

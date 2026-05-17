<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;
use Timeshit\Util\Ansi;
use Timeshit\Util\Io;
use const PHP_BINARY;
use function ctype_digit;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function fwrite;
use function is_file;
use function posix_kill;
use function stream_socket_client;
use function trim;
use function unlink;

final class LocalServer
{
    private const PID_FILE = '/data/server.pid';
    private const SCRIPT = '/server.php';

    public function __construct(
        private readonly string $pidFile,
        private readonly string $script,
        private readonly int $port,
        private readonly Io $io,
    ) {}

    public static function forRoot(string $rootDir, int $port, Io $io): self
    {
        return new self($rootDir . self::PID_FILE, $rootDir . self::SCRIPT, $port, $io);
    }

    /** Uptime in seconds, or null when the server is not reachable. */
    public function probeUptime(): ?int
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 0.5);
        if ($client === false) {
            return null;
        }
        fwrite($client, 'uptime');
        $response = trim((string) fread($client, 1024));
        fclose($client);
        if ($response === '' || !ctype_digit($response)) {
            return null;
        }

        return (int) $response;
    }

    public function cmd(?string $action): void
    {
        if ($action === null || $action === '') {
            throw new RuntimeException("server: missing action (expected 'start' or 'stop')");
        }
        try {
            $resolved = Resolver::matchCommand($action, ['start', 'stop']);
        } catch (RuntimeException $e) {
            throw new RuntimeException("server: " . $e->getMessage());
        }
        if ($resolved === null) {
            throw new RuntimeException("server: unknown action '{$action}' (expected 'start' or 'stop')");
        }
        match ($resolved) {
            'start' => $this->start(),
            'stop' => $this->stop(),
            default => throw new RuntimeException("server: no handler for resolved action '{$resolved}'"),
        };
    }

    /**
     * Auto-recovery: if the pid file exists, was not put into the explicit
     * `'stopped'` state by `server stop`, and the pid no longer points at a
     * live process (crash / OS kill / reboot), respawn the server. Missing
     * pid file → never started; do nothing.
     */
    public function autoStart(): void
    {
        if (!is_file($this->pidFile)) {
            return;
        }
        $contents = file_get_contents($this->pidFile);
        if ($contents === false) {
            return;
        }
        if (trim($contents) === 'stopped') {
            return;
        }
        if ($this->runningPid() !== null) {
            return;
        }
        $this->spawn();
        $this->io->err(Ansi::lblack('timeshit server auto-restarted') . "\n");
    }

    private function start(): void
    {
        $runningPid = $this->runningPid();
        if ($runningPid !== null) {
            $this->io->out(Ansi::lblack("timeshit server is already running (pid {$runningPid})") . "\n");

            return;
        }
        $this->spawn();
        $this->io->out("timeshit server started\n");
    }

    private function stop(): void
    {
        $runningPid = $this->runningPid();
        if ($runningPid === null) {
            if (is_file($this->pidFile) && trim((string) file_get_contents($this->pidFile)) === 'stopped') {
                $this->io->out(Ansi::lblack('timeshit server is already stopped') . "\n");
            } else {
                file_put_contents($this->pidFile, 'stopped');
                $this->io->out("timeshit server stopped\n");
            }

            return;
        }
        // Write the sentinel before killing so the server's shutdown handler leaves it alone.
        file_put_contents($this->pidFile, 'stopped');
        posix_kill($runningPid, 15);
        $this->io->out("timeshit server stopped (pid {$runningPid})\n");
    }

    private function spawn(): void
    {
        if (is_file($this->pidFile)) {
            unlink($this->pidFile);
        }
        exec('setsid ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script) . ' < /dev/null > /dev/null 2>&1 &');
    }

    private function runningPid(): ?int
    {
        if (!is_file($this->pidFile)) {
            return null;
        }
        $contents = file_get_contents($this->pidFile);
        if ($contents === false) {
            return null;
        }
        $value = trim($contents);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $pid = (int) $value;
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            return null;
        }

        return $pid;
    }
}
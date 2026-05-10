<?php declare(strict_types=1);

namespace Timeshit;

use Nette\Neon\Neon;
use RuntimeException;
use Timeshit\Util\Ansi;
use Timeshit\Util\Io;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function sprintf;
use function trim;

final class Configurator
{
    private const CONFIG_FILE = '/config/config.neon';
    private const SECRETS_FILE = '/config/secrets.neon';
    private const DEFAULT_TIMEZONE = 'Europe/Prague';

    public function __construct(
        private readonly string $rootDir,
        private readonly Io $io,
    ) {}

    public function run(): void
    {
        $configPath = $this->rootDir . self::CONFIG_FILE;
        $secretsPath = $this->rootDir . self::SECRETS_FILE;

        $existingConfig = is_file($configPath) ? $this->readNeonFile($configPath) : [];
        $existingBaseUrl = '';
        $existingTimezone = '';
        $bu = $existingConfig['youtrackBaseUrl'] ?? null;
        if (is_string($bu)) {
            $existingBaseUrl = $bu;
        }
        $tz = $existingConfig['timezone'] ?? null;
        if (is_string($tz)) {
            $existingTimezone = $tz;
        }

        $existingToken = '';
        if (is_file($secretsPath)) {
            $secrets = $this->readNeonFile($secretsPath);
            $tok = $secrets['youtrackToken'] ?? null;
            if (is_string($tok)) {
                $existingToken = $tok;
            }
        }

        $this->io->err(Ansi::lwhite('Configuring timeshit') . "\n\n");

        $baseUrl = $this->prompt('YouTrack base URL', $existingBaseUrl);
        if ($baseUrl === '') {
            throw new RuntimeException('configure: YouTrack base URL is required');
        }

        $timezone = $this->prompt('Timezone', $existingTimezone === '' ? self::DEFAULT_TIMEZONE : $existingTimezone);
        if ($timezone === '') {
            throw new RuntimeException('configure: timezone is required');
        }

        $tokenHint = $existingToken === '' ? '' : ' [press Enter to keep existing]';
        $this->io->err("YouTrack token{$tokenHint}: ");
        $line = $this->io->readLine();
        if ($line === null) {
            throw new RuntimeException('configure: failed to read input');
        }
        $token = trim($line);
        if ($token === '') {
            $token = $existingToken;
        }
        if ($token === '') {
            throw new RuntimeException('configure: YouTrack token is required');
        }

        $configDir = dirname($configPath);
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            throw new RuntimeException("configure: failed to create {$configDir}");
        }

        $merged = $existingConfig;
        $merged['youtrackBaseUrl'] = $baseUrl;
        $merged['timezone'] = $timezone;
        $configContents = Neon::encode($merged, Neon::BLOCK);
        if (file_put_contents($configPath, $configContents) === false) {
            throw new RuntimeException("configure: failed to write {$configPath}");
        }
        $this->io->err(sprintf("\nWrote %s\n", $configPath));

        $secretsContents = Neon::encode(
            ['youtrackToken' => $token],
            Neon::BLOCK,
        );
        if (file_put_contents($secretsPath, $secretsContents) === false) {
            throw new RuntimeException("configure: failed to write {$secretsPath}");
        }
        $this->io->err(sprintf("Wrote %s\n", $secretsPath));
    }

    private function prompt(string $label, string $default): string
    {
        $hint = $default === '' ? '' : " [{$default}]";
        $this->io->err("{$label}{$hint}: ");
        $line = $this->io->readLine();
        if ($line === null) {
            throw new RuntimeException('configure: failed to read input');
        }
        $trimmed = trim($line);

        return $trimmed === '' ? $default : $trimmed;
    }

    /** @return array<string, mixed> */
    private function readNeonFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = Neon::decode($raw);

        return is_array($decoded) ? $decoded : [];
    }
}
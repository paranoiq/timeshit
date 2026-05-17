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
    private const SECRETS_FILE = '/config/secrets.neon';

    public function __construct(
        private readonly string $rootDir,
        private readonly Io $io,
    ) {}

    public function run(): void
    {
        $secretsPath = $this->rootDir . self::SECRETS_FILE;

        $existingBaseUrl = '';
        $existingToken = '';
        if (is_file($secretsPath)) {
            $secrets = $this->readNeonFile($secretsPath);
            $bu = $secrets['youtrackBaseUrl'] ?? null;
            if (is_string($bu)) {
                $existingBaseUrl = $bu;
            }
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

        $secretsDir = dirname($secretsPath);
        if (!is_dir($secretsDir) && !mkdir($secretsDir, 0755, true) && !is_dir($secretsDir)) {
            throw new RuntimeException("configure: failed to create {$secretsDir}");
        }

        $secretsContents = Neon::encode(
            [
                'youtrackBaseUrl' => $baseUrl,
                'youtrackToken' => $token,
            ],
            Neon::BLOCK,
        );
        if (file_put_contents($secretsPath, $secretsContents) === false) {
            throw new RuntimeException("configure: failed to write {$secretsPath}");
        }
        $this->io->err(sprintf("\nWrote %s\n", $secretsPath));
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
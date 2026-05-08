<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function time;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class IssueCache
{
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly string $path) {}

    public function isFresh(): bool
    {
        if (!file_exists($this->path)) {
            return false;
        }
        $mtime = filemtime($this->path);
        if ($mtime === false) {
            return false;
        }

        return $mtime + self::TTL_SECONDS > time();
    }

    /** @return array{user: string, issues: list<Issue>} */
    public function load(): array
    {
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException("Failed to read cache: {$this->path}");
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Cache file is not a JSON object: {$this->path}");
        }
        $user = $decoded['user'] ?? null;
        if (!is_string($user)) {
            throw new RuntimeException("Cache missing 'user' field: {$this->path} (run 'refresh')");
        }
        $issuesRaw = $decoded['issues'] ?? null;
        if (!is_array($issuesRaw)) {
            throw new RuntimeException("Cache missing 'issues' field: {$this->path} (run 'refresh')");
        }
        $issues = [];
        foreach ($issuesRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $issues[] = Issue::fromArray($item);
        }

        return ['user' => $user, 'issues' => $issues];
    }

    /** @param list<Issue> $issues */
    public function save(string $user, array $issues): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create cache dir: {$dir}");
        }
        $payload = ['user' => $user, 'issues' => $issues];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json) === false) {
            throw new RuntimeException("Failed to write cache: {$this->path}");
        }
    }
}
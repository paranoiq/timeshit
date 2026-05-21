<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use Nette\Neon\Neon;
use RuntimeException;
use Timeshit\Util\FileLock;

use function array_map;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_array;
use function is_string;
use function time;

final class IssueCache
{
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return file_exists($this->path);
    }

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

    /** @return array{user: string, issues: list<Issue>, extraIds: list<string>, droppedIds: list<string>} */
    public function load(): array
    {
        return FileLock::shared($this->path, function (): array {
            $raw = file_get_contents($this->path);
            if ($raw === false) {
                throw new RuntimeException("Failed to read cache: {$this->path}");
            }
            $decoded = Neon::decode($raw);
            if (!is_array($decoded)) {
                throw new RuntimeException("Cache file is not a NEON map: {$this->path}");
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
            $extraIds = [];
            $extraRaw = $decoded['extraIds'] ?? null;
            if (is_array($extraRaw)) {
                foreach ($extraRaw as $item) {
                    if (is_string($item) && $item !== '') {
                        $extraIds[] = $item;
                    }
                }
            }
            $droppedIds = [];
            $droppedRaw = $decoded['droppedIds'] ?? null;
            if (is_array($droppedRaw)) {
                foreach ($droppedRaw as $item) {
                    if (is_string($item) && $item !== '') {
                        $droppedIds[] = $item;
                    }
                }
            }

            return ['user' => $user, 'issues' => $issues, 'extraIds' => $extraIds, 'droppedIds' => $droppedIds];
        });
    }

    /**
     * @param list<Issue> $issues
     * @param list<string> $extraIds ids referenced by commands that didn't show up
     *                               in me-queries — kept so refresh re-fetches them
     * @param list<string> $droppedIds ids filtered out by closedIssueRetentionDays — silenced on future refreshes
     */
    public function save(string $user, array $issues, array $extraIds = [], array $droppedIds = []): void
    {
        FileLock::exclusive($this->path, function () use ($user, $issues, $extraIds, $droppedIds): void {
            $payload = [
                'user' => $user,
                'issues' => array_map(static fn(Issue $i): array => (array) $i, $issues),
            ];
            if ($extraIds !== []) {
                $payload['extraIds'] = $extraIds;
            }
            if ($droppedIds !== []) {
                $payload['droppedIds'] = $droppedIds;
            }
            $neon = Neon::encode($payload, Neon::BLOCK);
            if (file_put_contents($this->path, $neon) === false) {
                throw new RuntimeException("Failed to write cache: {$this->path}");
            }
        });
    }
}

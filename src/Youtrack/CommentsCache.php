<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use Nette\Neon\Neon;
use RuntimeException;
use Timeshit\Util\FileLock;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_int;
use function is_string;

final class CommentsCache
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * @return array{
     *   lastChecked: int,
     *   snapshots: array<string, array{state: string, assignee: string, seenCommentIds: list<string>}>
     * }
     */
    public function load(): array
    {
        if (!file_exists($this->path)) {
            return ['lastChecked' => 0, 'snapshots' => []];
        }

        return FileLock::shared($this->path, function (): array {
            $raw = file_get_contents($this->path);
            if ($raw === false) {
                return ['lastChecked' => 0, 'snapshots' => []];
            }
            $decoded = Neon::decode($raw);
            if (!is_array($decoded)) {
                return ['lastChecked' => 0, 'snapshots' => []];
            }
            $lastChecked = $decoded['lastChecked'] ?? 0;
            if (!is_int($lastChecked)) {
                $lastChecked = 0;
            }
            $snapshots = [];
            $snapshotsRaw = $decoded['snapshots'] ?? [];
            if (is_array($snapshotsRaw)) {
                foreach ($snapshotsRaw as $issueId => $snap) {
                    if (!is_string($issueId) || !is_array($snap)) {
                        continue;
                    }
                    $state = $snap['state'] ?? '';
                    $assignee = $snap['assignee'] ?? '';
                    if (!is_string($state) || !is_string($assignee)) {
                        continue;
                    }
                    $ids = [];
                    $idsRaw = $snap['seenCommentIds'] ?? [];
                    if (is_array($idsRaw)) {
                        foreach ($idsRaw as $id) {
                            if (is_string($id) && $id !== '') {
                                $ids[] = $id;
                            }
                        }
                    }
                    $snapshots[$issueId] = [
                        'state' => $state,
                        'assignee' => $assignee,
                        'seenCommentIds' => $ids,
                    ];
                }
            }

            return ['lastChecked' => $lastChecked, 'snapshots' => $snapshots];
        });
    }

    /**
     * @param array<string, array{state: string, assignee: string, seenCommentIds: list<string>}> $snapshots
     */
    public function save(int $lastChecked, array $snapshots): void
    {
        FileLock::exclusive($this->path, function () use ($lastChecked, $snapshots): void {
            $payload = ['lastChecked' => $lastChecked, 'snapshots' => $snapshots];
            $neon = Neon::encode($payload, Neon::BLOCK);
            if (file_put_contents($this->path, $neon) === false) {
                throw new RuntimeException("Failed to write comments cache: {$this->path}");
            }
        });
    }
}
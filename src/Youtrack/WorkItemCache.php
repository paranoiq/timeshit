<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use Nette\Neon\Neon;
use RuntimeException;

use function array_map;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function time;

final class WorkItemCache
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

    /** @return array{user: string, items: list<WorkItem>} */
    public function load(): array
    {
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
        $itemsRaw = $decoded['items'] ?? null;
        if (!is_array($itemsRaw)) {
            throw new RuntimeException("Cache missing 'items' field: {$this->path} (run 'refresh')");
        }
        $items = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = WorkItem::fromArray($item);
        }

        return ['user' => $user, 'items' => $items];
    }

    /** @param list<WorkItem> $items */
    public function save(string $user, array $items): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create cache dir: {$dir}");
        }
        $payload = [
            'user' => $user,
            'items' => array_map(static fn(WorkItem $w): array => (array) $w, $items),
        ];
        $neon = Neon::encode($payload, Neon::BLOCK);
        if (file_put_contents($this->path, $neon) === false) {
            throw new RuntimeException("Failed to write cache: {$this->path}");
        }
    }
}
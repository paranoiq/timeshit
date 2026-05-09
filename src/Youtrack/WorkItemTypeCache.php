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
use function mkdir;
use function time;

final class WorkItemTypeCache
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

    /** @return list<WorkItemType> */
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
        $itemsRaw = $decoded['types'] ?? null;
        if (!is_array($itemsRaw)) {
            throw new RuntimeException("Cache missing 'types' field: {$this->path}");
        }
        $items = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = WorkItemType::fromArray($item);
        }

        return $items;
    }

    /** @param list<WorkItemType> $types */
    public function save(array $types): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create cache dir: {$dir}");
        }
        $payload = ['types' => array_map(static fn(WorkItemType $t): array => (array) $t, $types)];
        $neon = Neon::encode($payload, Neon::BLOCK);
        if (file_put_contents($this->path, $neon) === false) {
            throw new RuntimeException("Failed to write cache: {$this->path}");
        }
    }
}
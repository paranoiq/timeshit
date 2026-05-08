<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use RuntimeException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function time;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

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
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Cache file is not a JSON object: {$this->path}");
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
        $payload = ['types' => $types];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json) === false) {
            throw new RuntimeException("Failed to write cache: {$this->path}");
        }
    }
}
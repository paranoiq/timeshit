<?php declare(strict_types=1);

namespace Timeshit\Local;

use Nette\Neon\Neon;
use RuntimeException;

use function array_map;
use function array_pop;
use function array_values;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_int;
use function max;
use function mkdir;

final class FileRecordStore implements RecordStore
{
    /** Highest id ever generated; persisted as the top-level `lastId` in the NEON file so it survives archival of high-id records. */
    private ?int $lastId = null;

    public function __construct(private readonly string $path) {}

    /** @return list<Record> */
    public function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException("Failed to read: {$this->path}");
        }
        $decoded = Neon::decode($raw);
        if (!is_array($decoded)) {
            throw new RuntimeException("Not a NEON map: {$this->path}");
        }
        $itemsRaw = $decoded['items'] ?? null;
        if (!is_array($itemsRaw)) {
            return [];
        }
        $rawLastId = $decoded['lastId'] ?? null;
        $diskLastId = is_int($rawLastId) ? $rawLastId : null;

        $maxExistingId = 0;
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rid = $item['id'] ?? null;
            if (is_int($rid) && $rid > $maxExistingId) {
                $maxExistingId = $rid;
            }
        }

        $items = [];
        $needsBackfill = false;
        $nextFallback = max($diskLastId ?? 0, $maxExistingId) + 1;
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rid = $item['id'] ?? null;
            if (is_int($rid)) {
                $items[] = Record::fromArray($item);
            } else {
                $needsBackfill = true;
                $items[] = Record::fromArray($item, $nextFallback);
                $nextFallback++;
            }
        }

        $observedLastId = max($diskLastId ?? 0, $nextFallback - 1);
        if ($this->lastId === null || $observedLastId > $this->lastId) {
            $this->lastId = $observedLastId;
        }

        if ($needsBackfill || $diskLastId === null) {
            $this->save($items);
        }

        return $items;
    }

    public function nextId(): int
    {
        if ($this->lastId === null) {
            $this->load();
        }
        $this->lastId = ($this->lastId ?? 0) + 1;

        return $this->lastId;
    }

    public function appendClosed(Record $closed): void
    {
        if ($closed->isOpen()) {
            throw new RuntimeException('appendClosed: record must be closed');
        }
        $items = $this->load();
        $last = array_pop($items);
        if ($last !== null && $last->isOpen()) {
            $items[] = $closed;
            $items[] = $last;
        } else {
            if ($last !== null) {
                $items[] = $last;
            }
            $items[] = $closed;
        }
        $this->save($items);
    }

    /** @param list<Record> $items */
    public function save(array $items): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create dir: {$dir}");
        }
        $lastId = $this->lastId ?? 0;
        foreach ($items as $r) {
            if ($r->id > $lastId) {
                $lastId = $r->id;
            }
        }
        $this->lastId = $lastId;
        $payload = [
            'lastId' => $lastId,
            'items' => array_map(static fn(Record $r): array => $r->jsonSerialize(), $items),
        ];
        $neon = Neon::encode($payload, Neon::BLOCK);
        if (file_put_contents($this->path, $neon) === false) {
            throw new RuntimeException("Failed to write: {$this->path}");
        }
    }

    /** @return array{started: bool, stopped: ?Record} */
    public function track(Record $next, string $endTrigger): array
    {
        $items = $this->load();
        $last = array_pop($items);
        $stopped = null;
        if ($last !== null && $last->isOpen()) {
            if ($last->issueId === $next->issueId
                && $last->branch === $next->branch
                && $last->repo === $next->repo
                && $last->type === $next->type
            ) {
                return ['started' => false, 'stopped' => null];
            }
            $stopped = $last->withEnd($next->startedAt, $endTrigger, $next->createdAt);
            if (self::pausesViaTrigger($endTrigger)) {
                $stopped = $stopped->withStatus('paused', $next->createdAt);
            }
            $items[] = $stopped;
        } elseif ($last !== null) {
            $items[] = $last;
        }
        $items[] = $next;
        $this->save($items);

        return ['started' => true, 'stopped' => $stopped];
    }

    /** @return array{changed: bool, previousType: ?string, item: ?Record} */
    public function changeOpenType(string $newType, string $modifiedAt): array
    {
        $items = $this->load();
        $last = array_pop($items);
        if ($last === null || !$last->isOpen()) {
            if ($last !== null) {
                $items[] = $last;
            }

            return ['changed' => false, 'previousType' => null, 'item' => null];
        }
        if ($last->type === $newType) {
            $items[] = $last;

            return ['changed' => false, 'previousType' => $last->type, 'item' => $last];
        }
        $previous = $last->type;
        $updated = $last->withType($newType, $modifiedAt);
        $items[] = $updated;
        $this->save($items);

        return ['changed' => true, 'previousType' => $previous, 'item' => $updated];
    }

    /** @return array{ended: bool, item: ?Record} */
    public function endOpen(string $endedAt, string $endTrigger, ?string $appendComment): array
    {
        $items = $this->load();
        $last = array_pop($items);
        if ($last === null || !$last->isOpen()) {
            if ($last !== null) {
                $items[] = $last;
            }

            return ['ended' => false, 'item' => null];
        }
        $closed = $appendComment === null
            ? $last->withEnd($endedAt, $endTrigger, $endedAt)
            : $last->withEnd($endedAt, $endTrigger, $endedAt, self::mergeComment($last->comment, $appendComment));
        if (self::pausesViaTrigger($endTrigger)) {
            $closed = $closed->withStatus('paused', $endedAt);
        }
        $items[] = $closed;
        $this->save($items);

        return ['ended' => true, 'item' => $closed];
    }

    /** @return array{changed: bool, item: ?Record} */
    public function commentLast(string $comment, string $modifiedAt): array
    {
        $items = $this->load();
        $targetIndex = null;
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if ($items[$i]->startTrigger === 'day') {
                continue;
            }
            $targetIndex = $i;
            break;
        }
        if ($targetIndex === null) {
            return ['changed' => false, 'item' => null];
        }
        $target = $items[$targetIndex];
        $merged = self::mergeComment($target->comment, $comment);
        if ($merged === $target->comment) {
            return ['changed' => false, 'item' => $target];
        }
        $items[$targetIndex] = $target->withComment($merged, $modifiedAt);
        $this->save(array_values($items));

        return ['changed' => true, 'item' => $items[$targetIndex]];
    }

    private static function mergeComment(string $existing, string $more): string
    {
        if ($more === '') {
            return $existing;
        }
        if ($existing === '') {
            return $more;
        }

        return $existing . ' | ' . $more;
    }

    /** True when an end-trigger leaves the closed record in a pause state. */
    private static function pausesViaTrigger(string $endTrigger): bool
    {
        return $endTrigger === 'paused' || $endTrigger === 'interrupted';
    }
}
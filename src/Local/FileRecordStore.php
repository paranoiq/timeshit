<?php declare(strict_types=1);

namespace Timeshit\Local;

use Nette\Neon\Neon;
use RuntimeException;

use function array_map;
use function array_pop;
use function array_values;
use function count;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function is_array;
use function is_dir;
use function is_int;
use function max;
use function mkdir;

use const LOCK_EX;
use const LOCK_UN;

final class FileRecordStore implements RecordStore
{
    /** Highest id ever generated; persisted as the top-level `lastId` in the NEON file so it survives archival of high-id records. */
    private ?int $lastId = null;

    /** @var resource|null */
    private $lockHandle = null;

    private int $lockDepth = 0;

    public function __construct(private readonly string $path) {}

    public function transaction(callable $fn): mixed
    {
        if ($this->lockDepth === 0) {
            $this->ensureDir();
            $lockPath = $this->path . '.lock';
            $fp = fopen($lockPath, 'c');
            if ($fp === false) {
                throw new RuntimeException("Failed to open lock file: {$lockPath}");
            }
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                throw new RuntimeException("Failed to acquire exclusive lock: {$lockPath}");
            }
            $this->lockHandle = $fp;
        }
        $this->lockDepth++;
        try {
            return $fn();
        } finally {
            $this->lockDepth--;
            if ($this->lockDepth === 0 && $this->lockHandle !== null) {
                flock($this->lockHandle, LOCK_UN);
                fclose($this->lockHandle);
                $this->lockHandle = null;
            }
        }
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create dir: {$dir}");
        }
    }

    /** @return list<Record> */
    public function load(): array
    {
        return $this->transaction(function (): array {
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
        });
    }

    public function nextId(): int
    {
        return $this->transaction(function (): int {
            if ($this->lastId === null) {
                $this->load();
            }
            $this->lastId = ($this->lastId ?? 0) + 1;

            return $this->lastId;
        });
    }

    public function appendClosed(Record $closed): void
    {
        if ($closed->isOpen()) {
            throw new RuntimeException('appendClosed: record must be closed');
        }
        $this->transaction(function () use ($closed): void {
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
        });
    }

    /** @param list<Record> $items */
    public function save(array $items): void
    {
        $this->transaction(function () use ($items): void {
            $this->ensureDir();
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
        });
    }

    /** @return array{started: bool, stopped: ?Record} */
    public function track(Record $next, string $trigger, bool $pauseClosed = false): array
    {
        return $this->transaction(function () use ($next, $trigger, $pauseClosed): array {
            $items = $this->load();
            $last = array_pop($items);
            $stopped = null;
            if ($last !== null && $last->isOpen()) {
                if ($last->issueId === $next->issueId && $last->type === $next->type) {
                    return ['started' => false, 'stopped' => null];
                }
                $stopped = $last->withEnd($next->startedAt, $trigger);
                if ($pauseClosed) {
                    $stopped = $stopped->withStatus('paused');
                }
                $items[] = $stopped;
            } elseif ($last !== null) {
                $items[] = $last;
            }
            $items[] = $next;
            $this->save($items);

            return ['started' => true, 'stopped' => $stopped];
        });
    }

    /** @return array{changed: bool, previousType: ?string, item: ?Record} */
    public function changeOpenType(string $newType, string $modifiedAt, string $trigger): array
    {
        return $this->transaction(function () use ($newType, $modifiedAt, $trigger): array {
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
            $updated = $last->withType($newType, $modifiedAt, $trigger);
            $items[] = $updated;
            $this->save($items);

            return ['changed' => true, 'previousType' => $previous, 'item' => $updated];
        });
    }

    /** @return array{ended: bool, item: ?Record} */
    public function endOpen(string $endedAt, string $trigger, ?string $appendNote, bool $pauseClosed = false): array
    {
        return $this->transaction(function () use ($endedAt, $trigger, $appendNote, $pauseClosed): array {
            $items = $this->load();
            $last = array_pop($items);
            if ($last === null || !$last->isOpen()) {
                if ($last !== null) {
                    $items[] = $last;
                }

                return ['ended' => false, 'item' => null];
            }
            $closed = $appendNote === null
                ? $last->withEnd($endedAt, $trigger)
                : $last->withEnd($endedAt, $trigger, self::mergeNote($last->note, $appendNote));
            if ($pauseClosed) {
                $closed = $closed->withStatus('paused');
            }
            $items[] = $closed;
            $this->save($items);

            return ['ended' => true, 'item' => $closed];
        });
    }

    /** @return array{changed: bool, item: ?Record} */
    public function noteLast(string $note, string $modifiedAt, string $trigger): array
    {
        return $this->transaction(function () use ($note, $modifiedAt, $trigger): array {
            $items = $this->load();
            $targetIndex = null;
            for ($i = count($items) - 1; $i >= 0; $i--) {
                if ($items[$i]->status === 'day') {
                    continue;
                }
                $targetIndex = $i;
                break;
            }
            if ($targetIndex === null) {
                return ['changed' => false, 'item' => null];
            }
            $target = $items[$targetIndex];
            $merged = self::mergeNote($target->note, $note);
            if ($merged === $target->note) {
                return ['changed' => false, 'item' => $target];
            }
            $items[$targetIndex] = $target->withNote($merged, $modifiedAt, $trigger);
            $this->save(array_values($items));

            return ['changed' => true, 'item' => $items[$targetIndex]];
        });
    }

    private static function mergeNote(string $existing, string $more): string
    {
        if ($more === '') {
            return $existing;
        }
        if ($existing === '') {
            return $more;
        }

        return $existing . ' | ' . $more;
    }
}
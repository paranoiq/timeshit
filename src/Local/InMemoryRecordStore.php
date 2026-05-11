<?php declare(strict_types=1);

namespace Timeshit\Local;

use RuntimeException;

use function array_flip;
use function array_merge;
use function array_pop;
use function array_values;
use function count;

final class InMemoryRecordStore implements RecordStore
{
    /** @var list<Record> */
    private array $items;

    /** @var list<Record> */
    private array $archived = [];

    private int $lastId;

    /**
     * @param list<Record> $initial
     * @param int|null     $lastId  override starting counter; defaults to `max(initial.id, 0)`
     */
    public function __construct(array $initial = [], ?int $lastId = null)
    {
        $this->items = $initial;
        $max = 0;
        foreach ($initial as $r) {
            if ($r->id > $max) {
                $max = $r->id;
            }
        }
        $this->lastId = $lastId ?? $max;
    }

    public function transaction(callable $fn): mixed
    {
        return $fn();
    }

    /** @return list<Record> */
    public function load(): array
    {
        return $this->items;
    }

    public function nextId(): int
    {
        $this->lastId++;

        return $this->lastId;
    }

    /** @param list<Record> $items */
    public function save(array $items): void
    {
        $this->items = $items;
        foreach ($items as $r) {
            if ($r->id > $this->lastId) {
                $this->lastId = $r->id;
            }
        }
    }

    public function appendClosed(Record $closed): void
    {
        if ($closed->isOpen()) {
            throw new RuntimeException('appendClosed: record must be closed');
        }
        $items = $this->items;
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
        $this->items = $items;
    }

    /** @return array{started: bool, stopped: ?Record} */
    public function track(Record $next, string $trigger, bool $pauseClosed = false): array
    {
        $items = $this->items;
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
        $this->items = $items;

        return ['started' => true, 'stopped' => $stopped];
    }

    /** @return array{changed: bool, previousType: ?string, item: ?Record} */
    public function changeOpenType(string $newType, string $modifiedAt, string $trigger): array
    {
        $items = $this->items;
        $last = array_pop($items);
        if ($last === null || !$last->isOpen()) {
            return ['changed' => false, 'previousType' => null, 'item' => null];
        }
        if ($last->type === $newType) {
            return ['changed' => false, 'previousType' => $last->type, 'item' => $last];
        }
        $previous = $last->type;
        $updated = $last->withType($newType, $modifiedAt, $trigger);
        $items[] = $updated;
        $this->items = $items;

        return ['changed' => true, 'previousType' => $previous, 'item' => $updated];
    }

    /** @return array{ended: bool, item: ?Record} */
    public function endOpen(string $endedAt, string $trigger, ?string $appendNote, bool $pauseClosed = false): array
    {
        $items = $this->items;
        $last = array_pop($items);
        if ($last === null || !$last->isOpen()) {
            return ['ended' => false, 'item' => null];
        }
        $closed = $appendNote === null
            ? $last->withEnd($endedAt, $trigger)
            : $last->withEnd($endedAt, $trigger, self::mergeNote($last->note, $appendNote));
        if ($pauseClosed) {
            $closed = $closed->withStatus('paused');
        }
        $items[] = $closed;
        $this->items = $items;

        return ['ended' => true, 'item' => $closed];
    }

    /** @return array{changed: bool, item: ?Record} */
    public function noteLast(string $note, string $modifiedAt, string $trigger): array
    {
        $items = $this->items;
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
        $this->items = array_values($items);

        return ['changed' => true, 'item' => $this->items[$targetIndex]];
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

    /**
     * @param list<int> $ids
     * @return list<Record>
     */
    public function archive(array $ids, string $workItemId, string $time, string $trigger): array
    {
        $idSet = array_flip($ids);
        $kept = [];
        $archived = [];
        foreach ($this->items as $r) {
            if (isset($idSet[$r->id])) {
                $archived[] = $r->markSynced($workItemId, $time, $trigger);
            } else {
                $kept[] = $r;
            }
        }
        if ($archived === []) {
            return [];
        }
        $this->items = $kept;
        $this->archived = array_merge($this->archived, $archived);

        return $archived;
    }

    /**
     * @param list<int> $ids
     * @return list<Record>
     */
    public function markFailed(array $ids, string $reason, string $time, string $trigger): array
    {
        $idSet = array_flip($ids);
        $updated = [];
        $items = $this->items;
        foreach ($items as $i => $r) {
            if (isset($idSet[$r->id])) {
                $items[$i] = $r->markFailed($reason, $time, $trigger);
                $updated[] = $items[$i];
            }
        }
        if ($updated === []) {
            return [];
        }
        $this->items = $items;

        return $updated;
    }

    /** @return list<Record> */
    public function loadArchive(): array
    {
        return $this->archived;
    }
}
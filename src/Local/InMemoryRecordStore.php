<?php declare(strict_types=1);

namespace Timeshit\Local;

use RuntimeException;

use function array_pop;
use function array_values;
use function count;

final class InMemoryRecordStore implements RecordStore
{
    /** @var list<Record> */
    private array $items;

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
    public function track(Record $next, string $endTrigger): array
    {
        $items = $this->items;
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
            $items[] = $stopped;
        } elseif ($last !== null) {
            $items[] = $last;
        }
        $items[] = $next;
        $this->items = $items;

        return ['started' => true, 'stopped' => $stopped];
    }

    /** @return array{changed: bool, previousType: ?string, item: ?Record} */
    public function changeOpenType(string $newType, string $modifiedAt): array
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
        $updated = $last->withType($newType, $modifiedAt);
        $items[] = $updated;
        $this->items = $items;

        return ['changed' => true, 'previousType' => $previous, 'item' => $updated];
    }

    /** @return array{ended: bool, item: ?Record} */
    public function endOpen(string $endedAt, string $endTrigger, ?string $appendComment): array
    {
        $items = $this->items;
        $last = array_pop($items);
        if ($last === null || !$last->isOpen()) {
            return ['ended' => false, 'item' => null];
        }
        $closed = $appendComment === null
            ? $last->withEnd($endedAt, $endTrigger, $endedAt)
            : $last->withEnd($endedAt, $endTrigger, $endedAt, self::mergeComment($last->comment, $appendComment));
        $items[] = $closed;
        $this->items = $items;

        return ['ended' => true, 'item' => $closed];
    }

    /** @return array{changed: bool, item: ?Record} */
    public function commentLast(string $comment, string $modifiedAt): array
    {
        $items = $this->items;
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
        $this->items = array_values($items);

        return ['changed' => true, 'item' => $this->items[$targetIndex]];
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
}
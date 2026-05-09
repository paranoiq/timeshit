<?php declare(strict_types=1);

namespace Timeshit\Local;

use Nette\Neon\Neon;
use RuntimeException;

use function array_map;
use function array_pop;
use function array_values;
use function count;
use function date;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function mkdir;

final class Store
{
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
        $items = [];
        foreach ($itemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = Record::fromArray($item);
        }

        return $items;
    }

    /**
     * Appends a closed record to the store. If the latest existing record is
     * open, the new closed record is inserted just before it so the open
     * record remains the last entry (preserving the "open is always latest"
     * invariant).
     */
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
        $payload = ['items' => array_map(static fn(Record $r): array => $r->jsonSerialize(), $items)];
        $neon = Neon::encode($payload, Neon::BLOCK);
        if (file_put_contents($this->path, $neon) === false) {
            throw new RuntimeException("Failed to write: {$this->path}");
        }
    }

    /**
     * Closes the open entry (if any) with the given end timestamp and trigger,
     * then appends a new open entry. If the open entry already matches the new
     * one (same issueId, branch, repo, type), nothing is written.
     *
     * @return array{started: bool, stopped: ?Record}
     *     started: true when a new entry was appended;
     *     stopped: the just-closed previous entry (with endedAt set), or null
     *     when there was no prior open entry or the call was a no-op.
     */
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
            $items[] = $stopped;
        } elseif ($last !== null) {
            $items[] = $last;
        }
        $items[] = $next;
        $this->save($items);

        return ['started' => true, 'stopped' => $stopped];
    }

    /**
     * Replaces the type of the latest open entry. No-op when the open entry
     * already has this type.
     *
     * @return array{changed: bool, previousType: ?string, item: ?Record}
     *     changed: true when the file was rewritten;
     *     previousType: the prior type when there was an open entry, else null;
     *     item: the (now updated) open entry, or null when no entry was open.
     */
    public function changeOpenType(string $newType): array
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
        $updated = $last->withType($newType, date('Y-m-d H:i'));
        $items[] = $updated;
        $this->save($items);

        return ['changed' => true, 'previousType' => $previous, 'item' => $updated];
    }

    /**
     * Closes the latest open entry. When $appendComment is non-null and
     * non-empty it is appended to the entry's existing comment using
     * `' | '` as separator.
     *
     * @return array{ended: bool, item: ?Record}
     *     ended: true when the file was rewritten;
     *     item: the now-closed entry, or null when there was no open entry.
     */
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
        $items[] = $closed;
        $this->save($items);

        return ['ended' => true, 'item' => $closed];
    }

    /**
     * Appends the given text to the comment of the latest non-day entry
     * (whether open or closed), joined by `' | '`. Day records (those with
     * `startTrigger === 'day'`) are skipped — their comments are managed by
     * the `day` command flow, not by ad-hoc `comment` calls. No-op when the
     * merged result is identical to the existing comment (e.g. appending an
     * empty string).
     *
     * @return array{changed: bool, item: ?Record}
     */
    public function commentLast(string $comment): array
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
        $items[$targetIndex] = $target->withComment($merged, date('Y-m-d H:i'));
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
}

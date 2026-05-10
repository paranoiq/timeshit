<?php declare(strict_types=1);

namespace Timeshit\Local;

interface RecordStore
{
    /**
     * Runs `$fn` while holding an exclusive lock on the underlying storage.
     * All `load` / `save` / `nextId` / mutator calls made from within the
     * callback are atomic with respect to other processes. Re-entrant: a
     * `transaction` call from inside another `transaction` does not
     * re-acquire the lock.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed;

    /** @return list<Record> */
    public function load(): array;

    /**
     * Next auto-increment id for a freshly-created record. When a single
     * command writes multiple records in one transaction (e.g. `grab`),
     * the caller obtains the base id once and uses `id`, `id+1`, ... — no
     * mid-transaction persistence needed.
     */
    public function nextId(): int;

    /** @param list<Record> $items */
    public function save(array $items): void;

    /**
     * Appends a closed record. If the latest record is open, the new closed
     * record is inserted just before it so the open record remains last
     * (preserving the "open is always latest" invariant).
     */
    public function appendClosed(Record $closed): void;

    /**
     * Closes the open entry (if any) with the given trigger (a command name,
     * recorded into the closed record's log), then appends the new open
     * entry. No-op when the open entry already matches `$next` on
     * `issueId`/`type`. When `$pauseClosed` is true, the closed record's
     * status is flipped to `'paused'`.
     *
     * @return array{started: bool, stopped: ?Record}
     */
    public function track(Record $next, string $trigger, bool $pauseClosed = false): array;

    /**
     * Replaces the type of the latest open entry. No-op when it already has
     * this type.
     *
     * @return array{changed: bool, previousType: ?string, item: ?Record}
     */
    public function changeOpenType(string $newType, string $modifiedAt, string $trigger): array;

    /**
     * Closes the latest open entry. When `$appendComment` is non-null and
     * non-empty it is appended to the existing comment with `' | '`. When
     * `$pauseClosed` is true, the closed record's status is flipped to
     * `'paused'`.
     *
     * @return array{ended: bool, item: ?Record}
     */
    public function endOpen(string $endedAt, string $trigger, ?string $appendComment, bool $pauseClosed = false): array;

    /**
     * Appends `$comment` to the comment of the latest non-day entry, joined
     * by `' | '`. No-op when the merged result is identical (e.g. empty
     * append).
     *
     * @return array{changed: bool, item: ?Record}
     */
    public function commentLast(string $comment, string $modifiedAt, string $trigger): array;
}
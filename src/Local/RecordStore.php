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
     * Replaces the type of the latest non-`day`, non-`untracked` entry (open
     * or closed). No-op when it already has this type. Returns a null item
     * when no eligible entry exists.
     *
     * @return array{changed: bool, previousType: ?string, item: ?Record}
     */
    public function changeLastType(string $newType, string $modifiedAt, string $trigger): array;

    /**
     * Closes the latest open entry. When `$appendNote` is non-null and
     * non-empty it is appended to the existing note with `' | '`. When
     * `$pauseClosed` is true, the closed record's status is flipped to
     * `'paused'`.
     *
     * @return array{ended: bool, item: ?Record}
     */
    public function endOpen(string $endedAt, string $trigger, ?string $appendNote, bool $pauseClosed = false): array;

    /**
     * Appends `$note` to the note of the latest non-day entry, joined by
     * `' | '`. No-op when the merged result is identical (e.g. empty append).
     *
     * @return array{changed: bool, item: ?Record}
     */
    public function noteLast(string $note, string $modifiedAt, string $trigger): array;

    /**
     * Removes the records with the given ids from the active store, applies
     * `markSynced($workItemId, $time, $trigger)` to each, and appends them to
     * the archive. Records not currently in the active store are silently
     * ignored. Returns the archived records (post-mutation).
     *
     * @param list<int> $ids
     * @return list<Record>
     */
    public function archive(array $ids, string $workItemId, string $time, string $trigger): array;

    /**
     * Applies `markFailed($reason, $time, $trigger)` to each record with a
     * matching id and saves the active store. Records not present are
     * silently ignored. Returns the updated records.
     *
     * @param list<int> $ids
     * @return list<Record>
     */
    public function markFailed(array $ids, string $reason, string $time, string $trigger): array;

    /**
     * Removes the records with the given ids from the active store and appends
     * them to the archive with a `archived at <time> (<trigger>)` log entry
     * and their existing status preserved (no `markSynced` — there is no
     * work item). Used to evict `untracked` break records once the rest of
     * their day has been pushed. Records not present are silently ignored.
     *
     * @param list<int> $ids
     * @return list<Record>
     */
    public function archiveUntracked(array $ids, string $time, string $trigger): array;

    /** @return list<Record> */
    public function loadArchive(): array;
}
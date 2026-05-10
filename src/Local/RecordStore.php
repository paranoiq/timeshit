<?php declare(strict_types=1);

namespace Timeshit\Local;

interface RecordStore
{
    /** @return list<Record> */
    public function load(): array;

    /**
     * Next auto-increment id for a freshly-created record. When a single
     * command writes multiple records in one transaction (e.g. `steal`),
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
     * Closes the open entry (if any) with the given end timestamp/trigger,
     * then appends the new open entry. No-op when the open entry already
     * matches `$next` on `issueId`/`branch`/`repo`/`type`.
     *
     * @return array{started: bool, stopped: ?Record}
     */
    public function track(Record $next, string $endTrigger): array;

    /**
     * Replaces the type of the latest open entry. No-op when it already has
     * this type.
     *
     * @return array{changed: bool, previousType: ?string, item: ?Record}
     */
    public function changeOpenType(string $newType, string $modifiedAt): array;

    /**
     * Closes the latest open entry. When `$appendComment` is non-null and
     * non-empty it is appended to the existing comment with `' | '`.
     *
     * @return array{ended: bool, item: ?Record}
     */
    public function endOpen(string $endedAt, string $endTrigger, ?string $appendComment): array;

    /**
     * Appends `$comment` to the comment of the latest non-day entry, joined
     * by `' | '`. No-op when the merged result is identical (e.g. empty
     * append).
     *
     * @return array{changed: bool, item: ?Record}
     */
    public function commentLast(string $comment, string $modifiedAt): array;
}
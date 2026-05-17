<?php declare(strict_types=1);

namespace Timeshit\Local;

use DateTimeImmutable;
use Exception;
use JsonSerializable;
use RuntimeException;

use function array_key_exists;
use function intdiv;
use function is_int;
use function is_string;
use function max;

final class Record implements JsonSerializable
{
    /**
     * Lifecycle state of the record:
     *
     * - `'new'`     — fresh record (open or closed) not yet synced. Default.
     * - `'paused'`  — record is in a pause state (set by the `pause` command
     *                 OR by the interruption flow). Detected by `done` and
     *                 `resume` to find work to bring back.
     * - `'untracked'` — non-issue record (e.g. the break record produced by
     *                   `pause`) — never synced to YouTrack as a work item.
     * - `'day'`     — full-day OOO record produced by the `days` command.
     * - `'synced'`  — record was uploaded to YouTrack — placeholder.
     * - `'failed'`  — upload to YouTrack failed — placeholder.
     *
     * The `log` field is a `' | '`-separated audit trail. Each entry is a
     * single human-readable string with the form
     * `"<verb> <details> at <time> (<trigger>)"` where `<trigger>` is the
     * command name that produced the entry, e.g.:
     *   `created at 2026-05-09 10:00 (track)`
     *   `updated type Documentation at 2026-05-09 10:30 (type)`
     *   `edited startedAt from 2026-05-09 10:00 to 2026-05-09 09:45 at 2026-05-09 10:35 (before)`
     *   `closed at 2026-05-09 12:00 (end)`
     */
    public function __construct(
        public readonly int $id,
        public readonly string $issueId,
        public readonly string $type,
        public readonly string $startedAt,
        public readonly ?string $endedAt,
        public readonly string $log,
        public readonly string $note = '',
        public readonly string $status = 'new',
        public readonly string $workItemId = '',
    ) {}

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function minutes(): int
    {
        if ($this->endedAt === null) {
            return 0;
        }
        try {
            $s = new DateTimeImmutable($this->startedAt);
            $e = new DateTimeImmutable($this->endedAt);
        } catch (Exception) {
            return 0;
        }

        return max(0, intdiv($e->getTimestamp() - $s->getTimestamp(), 60));
    }

    public static function logCreated(string $time, string $trigger): string
    {
        return "created at {$time} ({$trigger})";
    }

    public static function logClosed(string $time, string $trigger): string
    {
        return "closed at {$time} ({$trigger})";
    }

    public static function logUpdate(string $field, string $value, string $time, string $trigger): string
    {
        return "updated {$field} {$value} at {$time} ({$trigger})";
    }

    public static function logEdit(string $field, string $from, string $to, string $time, string $trigger): string
    {
        return "edited {$field} from {$from} to {$to} at {$time} ({$trigger})";
    }

    public function appendLog(string $entry): self
    {
        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $this->endedAt,
            log: $this->log === '' ? $entry : $this->log . ' | ' . $entry,
            note: $this->note,
            status: $this->status,
            workItemId: $this->workItemId,
        );
    }

    public function withEnd(string $endedAt, string $trigger, ?string $note = null): self
    {
        $entry = self::logClosed($endedAt, $trigger);
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $endedAt,
            log: $log,
            note: $note ?? $this->note,
            status: $this->status,
            workItemId: $this->workItemId,
        );
    }

    public function withType(string $type, string $modifiedAt, string $trigger): self
    {
        $entry = self::logUpdate('type', $type, $modifiedAt, $trigger);
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $type,
            startedAt: $this->startedAt,
            endedAt: $this->endedAt,
            log: $log,
            note: $this->note,
            status: $this->status,
            workItemId: $this->workItemId,
        );
    }

    public function withNote(string $note, string $modifiedAt, string $trigger): self
    {
        $entry = self::logUpdate('note', '', $modifiedAt, $trigger);
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $this->endedAt,
            log: $log,
            note: $note,
            status: $this->status,
            workItemId: $this->workItemId,
        );
    }

    public function withStartedAt(string $startedAt, string $modifiedAt, string $trigger): self
    {
        $entry = self::logEdit('startedAt', $this->startedAt, $startedAt, $modifiedAt, $trigger);
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $startedAt,
            endedAt: $this->endedAt,
            log: $log,
            note: $this->note,
            status: $this->status,
            workItemId: $this->workItemId,
        );
    }

    public function withEndedAt(string $endedAt, string $modifiedAt, string $trigger): self
    {
        if ($this->endedAt === null) {
            throw new RuntimeException('withEndedAt: record is open (use withEnd to close it)');
        }
        $entry = self::logEdit('endedAt', $this->endedAt, $endedAt, $modifiedAt, $trigger);
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $endedAt,
            log: $log,
            note: $this->note,
            status: $this->status,
            workItemId: $this->workItemId,
        );
    }

    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $this->endedAt,
            log: $this->log,
            note: $this->note,
            status: $status,
            workItemId: $this->workItemId,
        );
    }

    public function markSynced(string $workItemId, string $time, string $trigger): self
    {
        $entry = "synced as {$workItemId} at {$time} ({$trigger})";
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $this->endedAt,
            log: $log,
            note: $this->note,
            status: 'synced',
            workItemId: $workItemId,
        );
    }

    public function markFailed(string $reason, string $time, string $trigger): self
    {
        $entry = "sync failed ({$reason}) at {$time} ({$trigger})";
        $log = $this->log === '' ? $entry : $this->log . ' | ' . $entry;

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            type: $this->type,
            startedAt: $this->startedAt,
            endedAt: $this->endedAt,
            log: $log,
            note: $this->note,
            status: 'failed',
            workItemId: $this->workItemId,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'issueId' => $this->issueId,
            'type' => $this->type,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'note' => $this->note,
            'log' => $this->log,
        ];
        if ($this->workItemId !== '') {
            $data['workItemId'] = $this->workItemId;
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param int $fallbackId used when the data lacks an `id` (legacy records); the file store assigns sequential ids and persists on next save
     */
    public static function fromArray(array $data, int $fallbackId = 0): self
    {
        $rawId = $data['id'] ?? null;
        $id = is_int($rawId) ? $rawId : $fallbackId;

        return new self(
            id: $id,
            issueId: self::str($data, 'issueId'),
            type: self::str($data, 'type'),
            startedAt: self::str($data, 'startedAt'),
            endedAt: self::nullableStr($data, 'endedAt'),
            log: self::nullableStr($data, 'log') ?? '',
            note: self::nullableStr($data, 'note') ?? self::nullableStr($data, 'comment') ?? '',
            status: self::nullableStr($data, 'status') ?? 'new',
            workItemId: self::nullableStr($data, 'workItemId') ?? '',
        );
    }

    /** @param array<int|string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("Invalid Record: '{$key}' missing or not a string");
        }

        return $value;
    }

    /** @param array<int|string, mixed> $data */
    private static function nullableStr(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException("Invalid Record: '{$key}' is not a string or null");
        }

        return $value;
    }
}
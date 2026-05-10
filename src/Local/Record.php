<?php declare(strict_types=1);

namespace Timeshit\Local;

use JsonSerializable;
use RuntimeException;

use function array_key_exists;
use function is_int;
use function is_string;

final class Record implements JsonSerializable
{
    /**
     * Lifecycle state of the record. Distinct from `endTrigger` (which
     * captures the literal cause of closure):
     *
     * - `'new'`     — fresh record (open or closed) not yet synced. Default.
     * - `'paused'`  — record is in a pause state (set by the `pause` command
     *                 OR by the interruption flow). Detected by `done` and
     *                 `resume` to find work to bring back.
     * - `'untracked'` — non-issue record (e.g. lunch break) — placeholder for
     *                   future commands; not currently produced by anything.
     * - `'synced'`  — record was uploaded to YouTrack — placeholder.
     * - `'failed'`  — upload to YouTrack failed — placeholder.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $issueId,
        public readonly ?string $branch,
        public readonly string $repo,
        public readonly string $type,
        public readonly string $startedAt,
        public readonly string $startTrigger,
        public readonly ?string $endedAt,
        public readonly ?string $endTrigger,
        public readonly string $createdAt,
        public readonly string $modifiedAt,
        public readonly string $comment = '',
        public readonly ?string $origStartedAt = null,
        public readonly ?string $origEndedAt = null,
        public readonly string $status = 'new',
    ) {}

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function withEnd(string $endedAt, string $endTrigger, string $modifiedAt, ?string $comment = null): self
    {
        return new self(
            id: $this->id,
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $endedAt,
            endTrigger: $endTrigger,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
            comment: $comment ?? $this->comment,
            origStartedAt: $this->origStartedAt,
            origEndedAt: $this->origEndedAt,
            status: $this->status,
        );
    }

    public function withType(string $type, string $modifiedAt): self
    {
        return new self(
            id: $this->id,
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $this->endedAt,
            endTrigger: $this->endTrigger,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
            comment: $this->comment,
            origStartedAt: $this->origStartedAt,
            origEndedAt: $this->origEndedAt,
            status: $this->status,
        );
    }

    public function withComment(string $comment, string $modifiedAt): self
    {
        return new self(
            id: $this->id,
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $this->endedAt,
            endTrigger: $this->endTrigger,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
            comment: $comment,
            origStartedAt: $this->origStartedAt,
            origEndedAt: $this->origEndedAt,
            status: $this->status,
        );
    }

    public function withStartedAt(string $startedAt, string $modifiedAt): self
    {
        $orig = $this->origStartedAt;
        if ($startedAt !== $this->startedAt && $orig === null) {
            $orig = $this->startedAt;
        }

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $this->endedAt,
            endTrigger: $this->endTrigger,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
            comment: $this->comment,
            origStartedAt: $orig,
            origEndedAt: $this->origEndedAt,
            status: $this->status,
        );
    }

    public function withEndedAt(string $endedAt, string $modifiedAt): self
    {
        if ($this->endedAt === null) {
            throw new RuntimeException('withEndedAt: record is open (use withEnd to close it)');
        }
        $orig = $this->origEndedAt;
        if ($endedAt !== $this->endedAt && $orig === null) {
            $orig = $this->endedAt;
        }

        return new self(
            id: $this->id,
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $endedAt,
            endTrigger: $this->endTrigger,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
            comment: $this->comment,
            origStartedAt: $this->origStartedAt,
            origEndedAt: $orig,
            status: $this->status,
        );
    }

    public function withStatus(string $status, string $modifiedAt): self
    {
        return new self(
            id: $this->id,
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $this->endedAt,
            endTrigger: $this->endTrigger,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
            comment: $this->comment,
            origStartedAt: $this->origStartedAt,
            origEndedAt: $this->origEndedAt,
            status: $status,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'id' => $this->id,
            'issueId' => $this->issueId,
            'branch' => $this->branch,
            'repo' => $this->repo,
            'type' => $this->type,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'startTrigger' => $this->startTrigger,
            'endedAt' => $this->endedAt,
            'endTrigger' => $this->endTrigger,
            'origStartedAt' => $this->origStartedAt,
            'origEndedAt' => $this->origEndedAt,
            'comment' => $this->comment,
            'createdAt' => $this->createdAt,
            'modifiedAt' => $this->modifiedAt,
        ];
        if ($this->branch === null) {
            unset($data['branch']);
        }
        if ($this->origStartedAt === null) {
            unset($data['origStartedAt']);
        }
        if ($this->origEndedAt === null) {
            unset($data['origEndedAt']);
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param int                      $fallbackId used when the data lacks an `id` (legacy records); the file store assigns sequential ids and persists on next save
     */
    public static function fromArray(array $data, int $fallbackId = 0): self
    {
        $startedAt = self::str($data, 'startedAt');
        $rawId = $data['id'] ?? null;
        $id = is_int($rawId) ? $rawId : $fallbackId;

        return new self(
            id: $id,
            issueId: self::str($data, 'issueId'),
            branch: self::nullableStr($data, 'branch'),
            repo: self::str($data, 'repo'),
            type: self::str($data, 'type'),
            startedAt: $startedAt,
            startTrigger: self::str($data, 'startTrigger'),
            endedAt: self::nullableStr($data, 'endedAt'),
            endTrigger: self::nullableStr($data, 'endTrigger'),
            createdAt: self::nullableStr($data, 'createdAt') ?? $startedAt,
            modifiedAt: self::nullableStr($data, 'modifiedAt') ?? $startedAt,
            comment: self::nullableStr($data, 'comment') ?? '',
            origStartedAt: self::nullableStr($data, 'origStartedAt'),
            origEndedAt: self::nullableStr($data, 'origEndedAt'),
            status: self::nullableStr($data, 'status') ?? 'new',
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
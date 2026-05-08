<?php declare(strict_types=1);

namespace Timeshit\Local;

use RuntimeException;

use function array_key_exists;
use function is_string;

final class Record
{
    public function __construct(
        public readonly string $issueId,
        public readonly string $branch,
        public readonly string $repo,
        public readonly string $type,
        public readonly string $startedAt,
        public readonly string $startTrigger,
        public readonly ?string $endedAt,
        public readonly ?string $endTrigger,
        public readonly string $comment = '',
    ) {}

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function withEnd(string $endedAt, string $endTrigger, ?string $comment = null): self
    {
        return new self(
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $endedAt,
            endTrigger: $endTrigger,
            comment: $comment ?? $this->comment,
        );
    }

    public function withType(string $type): self
    {
        return new self(
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $this->endedAt,
            endTrigger: $this->endTrigger,
            comment: $this->comment,
        );
    }

    public function withComment(string $comment): self
    {
        return new self(
            issueId: $this->issueId,
            branch: $this->branch,
            repo: $this->repo,
            type: $this->type,
            startedAt: $this->startedAt,
            startTrigger: $this->startTrigger,
            endedAt: $this->endedAt,
            endTrigger: $this->endTrigger,
            comment: $comment,
        );
    }

    /** @param array<int|string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            issueId: self::str($data, 'issueId'),
            branch: self::str($data, 'branch'),
            repo: self::str($data, 'repo'),
            type: self::str($data, 'type'),
            startedAt: self::str($data, 'startedAt'),
            startTrigger: self::str($data, 'startTrigger'),
            endedAt: self::nullableStr($data, 'endedAt'),
            endTrigger: self::nullableStr($data, 'endTrigger'),
            comment: self::nullableStr($data, 'comment') ?? '',
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
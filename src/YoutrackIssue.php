<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;

use function is_int;
use function is_string;

final class YoutrackIssue
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $project,
        public readonly string $state,
        public readonly string $type,
        public readonly string $category,
        public readonly string $assignee,
        public readonly int $spent,
    ) {}

    /** @param array<int|string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::str($data, 'id'),
            title: self::str($data, 'title'),
            project: self::str($data, 'project'),
            state: self::str($data, 'state'),
            type: self::str($data, 'type'),
            category: self::str($data, 'category'),
            assignee: self::str($data, 'assignee'),
            spent: self::int($data, 'spent'),
        );
    }

    /** @param array<int|string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("Invalid YoutrackIssue: '$key' missing or not a string");
        }
        return $value;
    }

    /** @param array<int|string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException("Invalid YoutrackIssue: '$key' missing or not an int");
        }
        return $value;
    }
}
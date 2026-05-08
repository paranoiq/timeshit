<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;

use function is_int;
use function is_string;

final class WorkItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $issueId,
        public readonly int $date,
        public readonly int $minutes,
        public readonly string $type,
        public readonly string $text,
    ) {}

    /** @param array<int|string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::str($data, 'id'),
            issueId: self::str($data, 'issueId'),
            date: self::int($data, 'date'),
            minutes: self::int($data, 'minutes'),
            type: self::str($data, 'type'),
            text: self::str($data, 'text'),
        );
    }

    /** @param array<int|string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("Invalid WorkItem: '{$key}' missing or not a string");
        }

        return $value;
    }

    /** @param array<int|string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException("Invalid WorkItem: '{$key}' missing or not an int");
        }

        return $value;
    }
}
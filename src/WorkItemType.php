<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;

use function is_string;

final class WorkItemType
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    /** @param array<int|string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::str($data, 'id'),
            name: self::str($data, 'name'),
        );
    }

    /** @param array<int|string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("Invalid WorkItemType: '$key' missing or not a string");
        }

        return $value;
    }
}
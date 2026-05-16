<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use RuntimeException;

use function is_array;
use function is_int;
use function is_string;

final class Issue
{
    /**
     * @param list<string> $roles
     * @param list<string> $tags
     * @param list<string> $customers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $project,
        public readonly string $state,
        public readonly string $type,
        public readonly string $category,
        public readonly string $assignee,
        public readonly int $spent,
        public readonly array $roles,
        public readonly string $description = '',
        public readonly array $tags = [],
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly ?int $resolved = null,
        public readonly array $customers = [],
        public readonly int $estimation = 0,
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
            roles: self::strList($data, 'roles'),
            description: self::strOpt($data, 'description'),
            tags: self::strListOpt($data, 'tags'),
            created: self::intOpt($data, 'created'),
            updated: self::intOpt($data, 'updated'),
            resolved: self::intOrNull($data, 'resolved'),
            customers: self::strListOpt($data, 'customers'),
            estimation: self::intOpt($data, 'estimation'),
        );
    }

    /**
     * @param array<int|string, mixed> $data
     * @return list<string>
     */
    private static function strList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("Invalid YoutrackIssue: '{$key}' missing or not an array");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException("Invalid YoutrackIssue: '{$key}' contains non-string");
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @param array<int|string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("Invalid YoutrackIssue: '{$key}' missing or not a string");
        }

        return $value;
    }

    /** @param array<int|string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException("Invalid YoutrackIssue: '{$key}' missing or not an int");
        }

        return $value;
    }

    /** @param array<int|string, mixed> $data */
    private static function strOpt(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<int|string, mixed> $data */
    private static function intOpt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /** @param array<int|string, mixed> $data */
    private static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return list<string>
     */
    private static function strListOpt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}

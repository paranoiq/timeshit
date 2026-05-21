<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use function date;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function strpos;
use function substr;

final class IssueComment
{
    public function __construct(
        public readonly string $id,
        public readonly string $issueId,
        public readonly string $author,
        public readonly string $created,
        public readonly string $text,
    ) {}

    /**
     * Parses a raw API comment object. Returns null for deleted comments or
     * entries missing required fields.
     *
     * @param array<int|string, mixed> $raw
     */
    public static function fromRaw(array $raw, string $issueId): ?self
    {
        $id = self::str($raw, 'id');
        if ($id === null || $id === '') {
            return null;
        }
        $deleted = $raw['deleted'] ?? false;
        if (is_bool($deleted) && $deleted) {
            return null;
        }

        $author = '-';
        $authorRaw = $raw['author'] ?? null;
        if (is_array($authorRaw)) {
            $login = self::str($authorRaw, 'login');
            if ($login !== null && $login !== '') {
                $author = self::dropDomain($login);
            }
        }

        $createdMs = $raw['created'] ?? null;
        $created = is_int($createdMs) ? date('Y-m-d H:i', intdiv($createdMs, 1000)) : '';
        $text = self::str($raw, 'text') ?? '';

        return new self(id: $id, issueId: $issueId, author: $author, created: $created, text: $text);
    }

    /** @param array<int|string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::str($data, 'id') ?? '',
            issueId: self::str($data, 'issueId') ?? '',
            author: self::str($data, 'author') ?? '',
            created: self::str($data, 'created') ?? '',
            text: self::str($data, 'text') ?? '',
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'issueId' => $this->issueId,
            'author' => $this->author,
            'created' => $this->created,
            'text' => $this->text,
        ];
    }

    /** @param array<int|string, mixed> $data */
    private static function str(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;

        return is_string($v) ? $v : null;
    }

    private static function dropDomain(string $name): string
    {
        $at = strpos($name, '@');

        return $at === false ? $name : substr($name, 0, $at);
    }
}
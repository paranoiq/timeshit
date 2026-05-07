<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;

use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function http_build_query;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function rtrim;
use function strpos;
use function substr;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

final class YoutrackClient
{
    private readonly string $baseUrl;
    private readonly string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /** @return array{login: string, fullName: string, email: string} */
    public function me(): array
    {
        $raw = $this->get('/api/users/me', ['fields' => 'login,fullName,email']);
        $login = self::asString($raw['login'] ?? null) ?? '';
        return [
            'login' => self::dropDomain($login),
            'fullName' => self::asString($raw['fullName'] ?? null) ?? '',
            'email' => self::asString($raw['email'] ?? null) ?? '',
        ];
    }

    /**
     * Issues the current user (per token) is involved in:
     * assigned, reported, commented on, or last updated by them.
     *
     * @return list<YoutrackIssue>
     */
    public function myIssues(int $top = 100): array
    {
        $query = 'assignee: me or commenter: me or reporter: me or updater: me';
        $fields = 'idReadable,summary,'
            . 'project(shortName,name),'
            . 'customFields(name,value(name,login,fullName,minutes,presentation))';

        $raw = $this->get('/api/issues', [
            'query' => $query,
            'fields' => $fields,
            '$top' => $top,
        ]);

        $result = [];
        foreach ($raw as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $result[] = self::parseIssue($issue);
        }
        return $result;
    }

    /**
     * @param array<int|string, scalar> $query
     * @return array<int|string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $url = $this->baseUrl . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("curl_init failed for $url");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException("YouTrack request failed: " . ($err !== '' ? $err : 'empty response'));
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("YouTrack $path returned HTTP $code: $body");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("YouTrack $path returned invalid JSON");
        }
        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $issue
     */
    private static function parseIssue(array $issue): YoutrackIssue
    {
        $id = self::asString($issue['idReadable'] ?? null) ?? '?';
        $title = self::asString($issue['summary'] ?? null) ?? '';

        $project = '?';
        $projectRaw = $issue['project'] ?? null;
        if (is_array($projectRaw)) {
            $project = self::asString($projectRaw['shortName'] ?? null)
                ?? self::asString($projectRaw['name'] ?? null)
                ?? '?';
        }

        return new YoutrackIssue(
            id: $id,
            title: $title,
            project: $project,
            state: self::cfName($issue, 'State') ?? '-',
            type: self::cfName($issue, 'Type') ?? '-',
            category: self::cfName($issue, 'Category') ?? '-',
            assignee: self::cfUser($issue, 'Assignee') ?? '-',
            spent: self::cfMinutes($issue, 'Spent time') ?? 0,
        );
    }

    /**
     * @param array<int|string, mixed> $issue
     */
    private static function customFieldValue(array $issue, string $name): mixed
    {
        $fields = $issue['customFields'] ?? null;
        if (!is_array($fields)) {
            return null;
        }
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['name'] ?? null) === $name) {
                return $field['value'] ?? null;
            }
        }
        return null;
    }

    /**
     * @param array<int|string, mixed> $issue
     */
    private static function cfName(array $issue, string $field): ?string
    {
        $value = self::customFieldValue($issue, $field);
        if (!is_array($value)) {
            return null;
        }
        return self::asString($value['name'] ?? null);
    }

    /**
     * @param array<int|string, mixed> $issue
     */
    private static function cfUser(array $issue, string $field): ?string
    {
        $value = self::customFieldValue($issue, $field);
        if (!is_array($value)) {
            return null;
        }
        $name = self::asString($value['login'] ?? null)
            ?? self::asString($value['fullName'] ?? null);
        return $name === null ? null : self::dropDomain($name);
    }

    private static function dropDomain(string $name): string
    {
        $at = strpos($name, '@');
        return $at === false ? $name : substr($name, 0, $at);
    }

    /**
     * @param array<int|string, mixed> $issue
     */
    private static function cfMinutes(array $issue, string $field): ?int
    {
        $value = self::customFieldValue($issue, $field);
        if (!is_array($value)) {
            return null;
        }
        $minutes = $value['minutes'] ?? null;
        return is_int($minutes) ? $minutes : null;
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
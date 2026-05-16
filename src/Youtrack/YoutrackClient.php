<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use RuntimeException;

use function curl_close;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function date;
use function http_build_query;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function rtrim;
use function strpos;
use function substr;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

final class YoutrackClient
{
    private const ISSUE_FIELDS = 'idReadable,summary,description,created,updated,resolved,'
        . 'project(shortName,name),'
        . 'tags(name),'
        . 'customFields(name,value(name,login,fullName,minutes,presentation))';

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
     * Fetches a single issue by its readable id (e.g. `SW-1234`). Returns
     * `null` when YouTrack reports the issue does not exist (HTTP 404).
     * Other errors (network, auth, 5xx, …) propagate as `RuntimeException`.
     */
    public function fetchIssue(string $id): ?Issue
    {
        try {
            $raw = $this->get('/api/issues/' . rawurlencode($id), ['fields' => self::ISSUE_FIELDS]);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                return null;
            }
            throw $e;
        }

        return self::parseIssue($raw, []);
    }

    /**
     * Fetches all issues the current user is involved in (one query per role
     * so we know *why* each was downloaded), plus all work items they authored.
     * Each issue carries a `roles` list naming which queries matched it.
     *
     * @return array{issues: list<Issue>, workItems: list<WorkItem>}
     */
    public function fetchMine(int $top = 1000): array
    {
        $issueFields = self::ISSUE_FIELDS;

        $roleQueries = [
            'assignee' => 'assignee: me',
            'commenter' => 'commenter: me',
            'reporter' => 'reporter: me',
            'updater' => 'updater: me',
            'starred' => 'tag: Star',
            'mentioned' => 'mentions: me',
        ];

        /** @var array<string, array{data: array<int|string, mixed>, roles: list<string>}> $byId */
        $byId = [];
        foreach ($roleQueries as $role => $query) {
            $raw = $this->get('/api/issues', [
                'query' => $query,
                'fields' => $issueFields,
                '$top' => $top,
            ]);
            foreach ($raw as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $id = self::asString($issue['idReadable'] ?? null);
                if ($id === null) {
                    continue;
                }
                if (!isset($byId[$id])) {
                    $byId[$id] = ['data' => $issue, 'roles' => []];
                }
                $byId[$id]['roles'][] = $role;
            }
        }

        $workItemsRaw = $this->get('/api/workItems', [
            'author' => 'me',
            'fields' => 'id,date,created,duration(minutes),text,type(name),'
                . 'issue(' . $issueFields . ')',
            '$top' => $top,
        ]);

        $workItems = [];
        foreach ($workItemsRaw as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $issueRaw = $rawItem['issue'] ?? null;
            if (!is_array($issueRaw)) {
                continue;
            }
            $issueId = self::asString($issueRaw['idReadable'] ?? null);
            if ($issueId === null) {
                continue;
            }

            $workItems[] = self::parseWorkItem($rawItem, $issueId);

            if (!isset($byId[$issueId])) {
                $byId[$issueId] = ['data' => $issueRaw, 'roles' => []];
            }
            if (!in_array('workAuthor', $byId[$issueId]['roles'], true)) {
                $byId[$issueId]['roles'][] = 'workAuthor';
            }
        }

        $issues = [];
        foreach ($byId as $entry) {
            $issues[] = self::parseIssue($entry['data'], $entry['roles']);
        }

        return ['issues' => $issues, 'workItems' => $workItems];
    }

    /**
     * Fetches the global list of work item types configured in YouTrack
     * (`/api/admin/timeTrackingSettings/workItemTypes`).
     *
     * @return list<WorkItemType>
     */
    public function fetchWorkItemTypes(): array
    {
        $raw = $this->get('/api/admin/timeTrackingSettings/workItemTypes', ['fields' => 'id,name']);
        $types = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = self::asString($item['id'] ?? null);
            $name = self::asString($item['name'] ?? null);
            if ($id === null || $name === null) {
                continue;
            }
            $types[] = new WorkItemType(id: $id, name: $name);
        }

        return $types;
    }

    /**
     * Creates a work item under `$issueId` with the given duration / type /
     * date / text and returns the YouTrack-assigned work-item id.
     *
     * `$dateMs` is the work-item date as UNIX epoch milliseconds.
     */
    public function createWorkItem(string $issueId, int $dateMs, int $minutes, string $typeId, string $text): string
    {
        $body = [
            'date' => $dateMs,
            'duration' => ['minutes' => $minutes],
            'type' => ['id' => $typeId],
            'text' => $text,
        ];
        $raw = $this->post('/api/issues/' . rawurlencode($issueId) . '/timeTracking/workItems', ['fields' => 'id'], $body);
        $id = self::asString($raw['id'] ?? null);
        if ($id === null || $id === '') {
            throw new RuntimeException("YouTrack returned no work-item id for {$issueId}");
        }

        return $id;
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
            throw new RuntimeException("curl_init failed for {$url}");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 1,
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
            throw new RuntimeException("YouTrack {$path} returned HTTP {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("YouTrack {$path} returned invalid JSON");
        }

        return $decoded;
    }

    /**
     * @param array<int|string, scalar> $query
     * @param array<int|string, mixed> $body
     * @return array<int|string, mixed>
     */
    private function post(string $path, array $query, array $body): array
    {
        $url = $this->baseUrl . $path . '?' . http_build_query($query);
        $payload = json_encode($body);
        if ($payload === false) {
            throw new RuntimeException("Failed to encode request body for {$path}");
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("curl_init failed for {$url}");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'Content-Type: application/json',
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
            throw new RuntimeException("YouTrack {$path} returned HTTP {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("YouTrack {$path} returned invalid JSON");
        }

        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $issue
     * @param list<string> $roles
     */
    private static function parseIssue(array $issue, array $roles): Issue
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

        $tags = [];
        $tagsRaw = $issue['tags'] ?? null;
        if (is_array($tagsRaw)) {
            foreach ($tagsRaw as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $name = self::asString($tag['name'] ?? null);
                if ($name !== null && $name !== '') {
                    $tags[] = $name;
                }
            }
        }

        $createdMs = self::asInt($issue['created'] ?? null);
        $updatedMs = self::asInt($issue['updated'] ?? null);
        $resolvedMs = self::asInt($issue['resolved'] ?? null);

        return new Issue(
            id: $id,
            title: $title,
            project: $project,
            state: self::cfName($issue, 'State') ?? '-',
            type: self::cfName($issue, 'Type') ?? '-',
            category: self::cfName($issue, 'Category') ?? '-',
            assignee: self::cfUser($issue, 'Assignee') ?? '-',
            spent: self::cfMinutes($issue, 'Spent time') ?? 0,
            roles: $roles,
            description: self::asString($issue['description'] ?? null) ?? '',
            tags: $tags,
            created: $createdMs === null ? 0 : intdiv($createdMs, 1000),
            updated: $updatedMs === null ? 0 : intdiv($updatedMs, 1000),
            resolved: $resolvedMs === null ? null : intdiv($resolvedMs, 1000),
            customers: self::cfNames($issue, 'Customer'),
            estimation: self::cfMinutes($issue, 'Estimation') ?? 0,
        );
    }

    /**
     * @param array<int|string, mixed> $raw
     */
    private static function parseWorkItem(array $raw, string $issueId): WorkItem
    {
        $id = self::asString($raw['id'] ?? null) ?? '?';
        $dateMs = self::asInt($raw['date'] ?? null) ?? self::asInt($raw['created'] ?? null) ?? 0;
        $date = date('Y-m-d', intdiv($dateMs, 1000));

        $minutes = 0;
        $duration = $raw['duration'] ?? null;
        if (is_array($duration)) {
            $minutes = self::asInt($duration['minutes'] ?? null) ?? 0;
        }

        $type = '-';
        $typeRaw = $raw['type'] ?? null;
        if (is_array($typeRaw)) {
            $type = self::asString($typeRaw['name'] ?? null) ?? '-';
        }

        $text = self::asString($raw['text'] ?? null) ?? '';

        return new WorkItem(
            id: $id,
            issueId: $issueId,
            date: $date,
            minutes: $minutes,
            type: $type,
            text: $text,
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
     * @return list<string>
     */
    private static function cfNames(array $issue, string $field): array
    {
        $value = self::customFieldValue($issue, $field);
        if (!is_array($value)) {
            return [];
        }
        $names = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = self::asString($item['name'] ?? null);
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
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

    private static function asInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}

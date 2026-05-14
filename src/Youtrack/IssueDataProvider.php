<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

interface IssueDataProvider
{
    /**
     * Returns the cached issues + work items + user, fetching from the API
     * when either cache is missing or stale.
     *
     * @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>}
     */
    public function loadOrFetch(): array;

    /** Forces a fresh fetch+cache, regardless of cache age. */
    public function refresh(): void;

    /**
     * Ensures that `$issueId` (a YouTrack-format readable id like `SW-1234`)
     * is represented in the issue cache. If the issue is not already cached,
     * it is fetched from YouTrack and added; the id is also recorded in a
     * separate `extraIds` list so it survives the next `refresh`. Offline
     * failures are swallowed — the id is still recorded so the next refresh
     * can pick it up.
     */
    public function ensureIssue(string $issueId): void;

    /**
     * Returns a `issueId => title` map from the issue cache, or an empty map
     * when no cache exists yet. Never fetches.
     *
     * @return array<string, string>
     */
    public function titles(): array;
}
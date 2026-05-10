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
     * Returns a `issueId => title` map from the issue cache, or an empty map
     * when no cache exists yet. Never fetches.
     *
     * @return array<string, string>
     */
    public function titles(): array;
}
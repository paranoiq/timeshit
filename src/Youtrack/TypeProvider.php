<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

interface TypeProvider
{
    /**
     * Returns the YouTrack work-item types, fetching from the API if the local
     * cache is missing or stale.
     *
     * @return list<WorkItemType>
     */
    public function types(): array;

    /** Forces a fresh fetch+cache, regardless of cache age. */
    public function refresh(): void;
}
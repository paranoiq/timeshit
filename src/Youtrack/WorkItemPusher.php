<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

interface WorkItemPusher
{
    /**
     * Posts a work item to YouTrack and returns the assigned work-item id.
     * Throws `\RuntimeException` on any failure (network, HTTP, parse).
     *
     * `$dateMs` is the work-item date as UNIX epoch milliseconds.
     */
    public function push(string $issueId, int $dateMs, int $minutes, string $typeId, string $text): string;
}
<?php declare(strict_types=1);

namespace Timeshit;

final class YoutrackIssue
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $project,
        public readonly string $state,
        public readonly string $type,
        public readonly string $category,
        public readonly string $assignee,
        public readonly string $spent,
    ) {}
}
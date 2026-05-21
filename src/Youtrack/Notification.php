<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

final class Notification
{
    public const KIND_ASSIGNED = 'assigned';
    public const KIND_STATE_CHANGE = 'state_change';
    public const KIND_COMMENT = 'comment';

    public function __construct(
        public readonly string $kind,
        public readonly string $issueId,
        public readonly string $issueTitle,
        public readonly string $author,
        public readonly string $payload,
    ) {}
}
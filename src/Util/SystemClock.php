<?php declare(strict_types=1);

namespace Timeshit\Util;

use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function nowMinute(): string
    {
        return $this->now()->format('Y-m-d H:i');
    }
}
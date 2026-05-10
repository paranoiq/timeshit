<?php declare(strict_types=1);

namespace Timeshit\Util;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;

    /** Returns the current time as `Y-m-d H:i` (the canonical timestamp format used in records). */
    public function nowMinute(): string;
}
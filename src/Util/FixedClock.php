<?php declare(strict_types=1);

namespace Timeshit\Util;

use DateMalformedStringException;
use DateTimeImmutable;
use RuntimeException;

final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $initial)
    {
        $this->now = new DateTimeImmutable($initial);
    }

    public function set(string $time): void
    {
        $this->now = new DateTimeImmutable($time);
    }

    /** Advances the clock by a `DateTimeImmutable::modify` expression (e.g. `+30m`, `+1h`, `-15m`). */
    public function advance(string $modifier): void
    {
        try {
            $this->now = $this->now->modify($modifier);
        } catch (DateMalformedStringException) {
            throw new RuntimeException("FixedClock: invalid modifier '{$modifier}'");
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function nowMinute(): string
    {
        return $this->now->format('Y-m-d H:i');
    }
}
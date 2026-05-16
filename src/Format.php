<?php declare(strict_types=1);

namespace Timeshit;

use DateTimeImmutable;
use Timeshit\Util\Ansi;
use function array_keys;
use function array_values;
use function explode;
use function in_array;
use function intdiv;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function str_replace;

final class Format
{
    /** Local record id rendered as `#N` in dim color, unpadded — callers pad with `sprintf` when a fixed-width column is needed. */
    public static function recordId(int $id): string
    {
        return Ansi::lblack('#' . $id);
    }

    public static function duration(string $startedAt, string $endedAt): string
    {
        $start = new DateTimeImmutable($startedAt);
        $end = new DateTimeImmutable($endedAt);

        return self::minutes(max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60)));
    }

    public static function minutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins === 0 ? "{$hours}h" : "{$hours}h {$mins}m";
    }

    /** Like `minutes`, but with the number in lwhite and the unit in lblack — for inline action messages. */
    public static function minutesInline(int $minutes): string
    {
        if ($minutes < 60) {
            return Ansi::lwhite((string) $minutes) . Ansi::lblack('m');
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $hPart = Ansi::lwhite((string) $hours) . Ansi::lblack('h');
        if ($mins === 0) {
            return $hPart;
        }

        return $hPart . ' ' . Ansi::lwhite((string) $mins) . Ansi::lblack('m');
    }

    public static function durationInline(string $startedAt, string $endedAt): string
    {
        $start = new DateTimeImmutable($startedAt);
        $end = new DateTimeImmutable($endedAt);

        return self::minutesInline(max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60)));
    }

    /** Like `type`, but without truncation or padding — for inline action messages. */
    public static function typeInline(string $type): string
    {
        $first = self::typeFirst($type);

        return self::colorizeType($first, $first);
    }

    public static function category(string $category): string
    {
        $shorts = [
            'Admin / Overhead / Support' => 'Admin',
            'Existing feature enhancement' => 'Enhancement',
            'Generic new feature' => 'Feature',
            'Internal tooling' => 'Tooling',
            'Technical debt' => 'Debt',
        ];

        return str_replace(array_keys($shorts), array_values($shorts), $category);
    }

    /** @param list<string> $roles */
    public static function roles(array $roles): string
    {
        $order = [
            'assignee'   => 'a',
            'commenter'  => 'c',
            'reporter'   => 'r',
            'updater'    => 'u',
            'workAuthor' => 'w',
            'starred'    => 's',
            'mentioned'  => 'm',
        ];
        $mask = '';
        foreach ($order as $role => $letter) {
            if (!in_array($role, $roles, true)) {
                $mask .= Ansi::lblack('-');
                continue;
            }
            $mask .= $role === 'workAuthor' ? Ansi::lgreen($letter) : $letter;
        }

        return $mask . ' ';
    }

    /** @param (callable(string): string)|null $color */
    public static function spent(int $totalMinutes, ?callable $color = null, bool $align = true): string
    {
        $apply = $color ?? static fn(string $s): string => $s;
        $minutes = $totalMinutes % 60;
        $totalHours = intdiv($totalMinutes, 60);
        $hours = $totalHours % 8;
        $days = intdiv($totalHours, 8);

        if ($align) {
            $daysPart = match (true) {
                $days === 0 => '   ',
                $days > 99 => $apply(' ∞') . Ansi::lblack('d'),
                default => $apply(sprintf('%2d', $days)) . Ansi::lblack('d'),
            };
            $hoursPart = $hours > 0 ? $apply(sprintf('%d', $hours)) . Ansi::lblack('h') : '  ';
            $minutesPart = $minutes > 0 || $totalMinutes === 0
                ? $apply(sprintf('%2d', $minutes)) . Ansi::lblack('m')
                : '   ';

            return $daysPart . ' ' . $hoursPart . ' ' . $minutesPart . ' ';
        }

        $parts = [];
        if ($days > 0) {
            $parts[] = ($days > 99 ? $apply('∞') : $apply((string) $days)) . Ansi::lblack('d');
        }
        if ($hours > 0) {
            $parts[] = $apply((string) $hours) . Ansi::lblack('h');
        }
        if ($minutes > 0 || $parts === []) {
            $parts[] = $apply((string) $minutes) . Ansi::lblack('m');
        }

        return implode(' ', $parts);
    }

    public static function assignee(string $assignee, string $currentUser): string
    {
        $isMe = $assignee === $currentUser;
        $padded = sprintf('%-17s', $isMe ? 'me' : $assignee);

        return $isMe ? Ansi::lgreen($padded) : $padded;
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'paused' => Ansi::lyellow($status),
            'synced' => Ansi::lgreen($status),
            'failed' => Ansi::lred($status),
            default  => Ansi::lblack($status),
        };
    }

    public static function type(string $type): string
    {
        $first = self::typeFirst($type);
        $trimmed = mb_strimwidth($first, 0, 17, '…');
        $padded = $trimmed . str_repeat(' ', max(0, 17 - mb_strwidth($trimmed)));

        return self::colorizeType($first, $padded);
    }

    /** First comma-separated segment of a type, with slash spacing normalized. */
    private static function typeFirst(string $type): string
    {
        $normalized = preg_replace('/\s*\/\s*/', '/', $type) ?? $type;

        return explode(',', $normalized)[0];
    }

    /** Applies the type-specific color to `$label` based on the canonical first segment `$first`. */
    private static function colorizeType(string $first, string $label): string
    {
        return match (true) {
            $first === 'Implementation' => Ansi::lgreen($label),
            $first === 'Test/Review'    => Ansi::cyan($label),
            $first === 'Documentation'  => Ansi::blue($label),
            $first === 'Communication'  => Ansi::yellow($label),
            $first === 'Out of office'  => Ansi::magenta($label),
            default => $label,
        };
    }

    public static function state(string $state): string
    {
        $display = $state === 'Sprint Scheduled' ? 'Scheduled' : $state;
        $padded = sprintf('%-12s', $display);

        return match (true) {
            in_array($state, ['Blocked'], true) => Ansi::red($padded),
            in_array($state, ['New', 'Submitted', 'Open', 'To Verify'], true) => Ansi::lwhite($padded),
            in_array($state, ['Scheduled', 'Sprint Scheduled'], true) => Ansi::lyellow($padded),
            in_array($state, ['In-progress'], true) => Ansi::lgreen($padded),
            in_array($state, ['Code Review'], true) => Ansi::lcyan($padded),
            in_array($state, ['In QA', 'Ready for QA'], true) => Ansi::cyan($padded),
            in_array($state, ['Done', 'Solved', 'Closed', 'Merged', 'Released', 'Verified', "Won't Fix", 'Duplicate', 'Cancelled'], true) => Ansi::lblack($padded),
            default => $padded,
        };
    }

    public static function statePriority(string $state): int
    {
        return match (true) {
            in_array($state, ['Blocked'], true) => 1,
            in_array($state, ['Code Review'], true) => 2,
            in_array($state, ['In-progress'], true) => 3,
            in_array($state, ['Sprint Scheduled'], true) => 4,
            in_array($state, ['Refinement', 'Reopened', 'Incomplete', 'To Verify'], true) => 5,
            in_array($state, ['New', 'Submitted', 'Open'], true) => 6,
            in_array($state, ['Done', 'Solved', 'Closed', 'Merged', 'Released', 'Verified', "Won't Fix", 'Duplicate', 'Cancelled'], true) => 7,
            default => 0,
        };
    }
}

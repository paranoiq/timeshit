<?php declare(strict_types=1);

namespace Timeshit;

use Closure;
use DateTimeImmutable;
use Timeshit\Util\Ansi;
use function array_keys;
use function array_values;
use function ceil;
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
use function ucwords;

final class Format
{
    /** Local record id rendered as `#n` in dim color, unpadded — callers pad with `sprintf` when a fixed-width column is needed. */
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
            'Existing feature enhancement' => 'Enhance',
            'Generic new feature' => 'Feature',
            'Internal tooling' => 'Tooling',
            'Technical debt' => 'Debt',
        ];
        $display = str_replace(array_keys($shorts), array_values($shorts), $category);
        $padded = sprintf('%-8s', $display);
        $color = match ($display) {
            'Bug'                => Ansi::lred(...),
            'Debt', 'Tooling'    => Ansi::lyellow(...),
            'Feature', 'Enhance' => Ansi::lgreen(...),
            default              => null,
        };

        return $color === null ? $padded : $color($padded);
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
    /** Rounded up to whole hours, aligned to 7 visible chars (`Xd Yh `), numbers in light blue. Empty string when zero. */
    public static function estimation(int $minutes): string
    {
        $totalHours = (int) ceil($minutes / 60);
        if ($totalHours === 0) {
            return '';
        }
        $hours = $totalHours % 8;
        $days = intdiv($totalHours, 8);
        $color = Ansi::lblue(...);
        $daysPart = match (true) {
            $days === 0 => '   ',
            $days > 99 => $color(' ∞') . Ansi::lblack('d'),
            default => $color(sprintf('%2d', $days)) . Ansi::lblack('d'),
        };
        $hoursPart = $hours > 0 ? $color(sprintf('%d', $hours)) . Ansi::lblack('h') : '  ';

        return $daysPart . ' ' . $hoursPart . ' ';
    }

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
        $display = match (true) {
            $isMe              => 'me',
            $assignee === '-'  => '-',
            default            => ucwords(str_replace('.', ' ', $assignee)),
        };
        $trimmed = mb_strimwidth($display, 0, 14, '…');
        $padded = $trimmed . str_repeat(' ', max(0, 14 - mb_strwidth($trimmed)));

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

    /**
     * Single source of truth for the per-row status glyph + color used across the views.
     * Kinds: `open` = still running, `synced` = fresh YouTrack work item, `archived` = local record
     * already pushed (de-emphasized), `failed` = push failed, `local` = closed but not yet pushed.
     */
    public static function indicator(string $kind): string
    {
        return match ($kind) {
            'open'     => Ansi::lgreen('▶'),
            'synced'   => Ansi::lgreen('●'),
            'archived' => Ansi::lblack('▣'),
            'failed'   => Ansi::red('✗'),
            'local'    => Ansi::lyellow('○'),
            default    => ' ',
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
        $display = match ($state) {
            'Sprint Scheduled'              => 'Scheduled',
            'QA Approved - Ready for merge' => 'QA Approved',
            default                         => $state,
        };
        $padded = sprintf('%-12s', $display);
        $color = self::states()[$state]['color'] ?? null;

        return $color === null ? $padded : $color($padded);
    }

    public static function statePriority(string $state): int
    {
        return self::states()[$state]['priority'] ?? 0;
    }

    /**
     * Single source of truth for known YouTrack states. `color` is the Ansi wrapper applied by
     * {@see state()} (null = no color); `priority` is the sort key used by {@see statePriority()}
     * (lower = more attention; 0 = unranked / unknown).
     *
     * @return array<string, array{color: ?Closure, priority: int}>
     */
    private static function states(): array
    {
        return [
            'Blocked'          => ['color' => Ansi::red(...),     'priority' => 1],

            'In-progress'      => ['color' => Ansi::lgreen(...),  'priority' => 2],

            'Code Review'      => ['color' => Ansi::lcyan(...),   'priority' => 3],

            'In QA'            => ['color' => Ansi::cyan(...),    'priority' => 4],
            'Ready for QA'     => ['color' => Ansi::cyan(...),    'priority' => 4],
            'QA Approved'      => ['color' => Ansi::cyan(...),    'priority' => 4],
            'QA Approved - Ready for merge' => ['color' => Ansi::cyan(...), 'priority' => 4],

            'Sprint Scheduled' => ['color' => Ansi::lyellow(...), 'priority' => 5],
            'Scheduled'        => ['color' => Ansi::lyellow(...), 'priority' => 5],

            'Refinement'       => ['color' => Ansi::lwhite(...),  'priority' => 6],
            'Reopened'         => ['color' => Ansi::lwhite(...),  'priority' => 6],
            'Incomplete'       => ['color' => Ansi::lwhite(...),  'priority' => 6],
            'To Verify'        => ['color' => Ansi::lwhite(...),  'priority' => 6],

            'New'              => ['color' => Ansi::lwhite(...),  'priority' => 7],
            'Submitted'        => ['color' => Ansi::lwhite(...),  'priority' => 7],
            'Open'             => ['color' => Ansi::lwhite(...),  'priority' => 7],

            'Done'             => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Solved'           => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Closed'           => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Merged'           => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Released'         => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Verified'         => ['color' => Ansi::lblack(...),  'priority' => 8],
            "Won't Fix"        => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Duplicate'        => ['color' => Ansi::lblack(...),  'priority' => 8],
            'Cancelled'        => ['color' => Ansi::lblack(...),  'priority' => 8],
        ];
    }
}

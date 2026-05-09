<?php declare(strict_types=1);

namespace Timeshit;

use Closure;
use DateTimeImmutable;
use Exception;
use RuntimeException;
use Timeshit\Youtrack\WorkItemType;

use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function mb_strtolower;
use function preg_match;
use function str_starts_with;
use function strtoupper;

/**
 * Pure helpers that turn user-provided CLI input into canonical values:
 * dates, issue ids, work-item types, and command names.
 *
 * Everything here is `static`; no I/O, no caching, no exit() side-effects.
 * Callers (mostly `App`) are responsible for loading caches and handling the
 * exceptions thrown on bad input.
 */
final class Resolver
{
    /**
     * The whitelist of YouTrack work-item types we currently allow on local
     * records. Anything outside this list is rejected by `matchType`.
     */
    public const ALLOWED_TYPES = [
        'Analyses / Design',
        'Communication, Meetings, ...',
        'Documentation',
        'Implementation',
        'Out of office',
        'Test / Review',
    ];

    /** @param array<int, string> $argv */
    public static function restArgs(array $argv): ?string
    {
        $rest = array_slice($argv, 2);
        if ($rest === []) {
            return null;
        }

        return implode(' ', $rest);
    }

    public static function requireIssueId(string $cmd, ?string $issue): string
    {
        if ($issue === null || $issue === '') {
            throw new RuntimeException("{$cmd}: missing <issue>");
        }
        if (preg_match('/^[A-Za-z]+-\d+$/', $issue) !== 1) {
            throw new RuntimeException("{$cmd}: invalid issue '{$issue}' (expected format like ABC-123)");
        }

        return strtoupper($issue);
    }

    public static function extractIssueId(string $branch): string
    {
        if (preg_match('/^([A-Za-z]{1,3}-\d+)\b/', $branch, $m) === 1) {
            return strtoupper($m[1]);
        }

        return $branch;
    }

    public static function resolveDate(?string $input): DateTimeImmutable
    {
        $original = $input === null || $input === '' ? 'today' : $input;
        if (preg_match('/^\d+$/', $original) === 1) {
            return self::dayOfCurrentMonth((int) $original, $original);
        }
        $offsets = [
            'today' => 0,
            'yesterday' => -1,
            'tomorrow' => 1,
            'ereyesterday' => -2,
            'overmorrow' => 2,
        ];
        $needle = mb_strtolower($original);
        $matches = [];
        foreach (array_keys($offsets) as $keyword) {
            if (str_starts_with($keyword, $needle)) {
                $matches[] = $keyword;
            }
        }
        if (count($matches) === 1) {
            return (new DateTimeImmutable('today'))->modify($offsets[$matches[0]] . ' days');
        }
        if (count($matches) > 1) {
            throw new RuntimeException("day: ambiguous date '{$original}', could be: " . implode(', ', $matches));
        }
        try {
            return new DateTimeImmutable($original);
        } catch (Exception) {
            throw new RuntimeException("day: invalid date '{$original}'");
        }
    }

    private static function dayOfCurrentMonth(int $day, string $original): DateTimeImmutable
    {
        $today = new DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $month = (int) $today->format('m');
        $resolved = $today->setDate($year, $month, $day);
        if ((int) $resolved->format('j') !== $day || (int) $resolved->format('n') !== $month) {
            throw new RuntimeException("day: invalid day-of-month '{$original}' for the current month");
        }

        return $resolved;
    }

    /**
     * Wraps `matchType` with the default/missing handling. When `$input` is
     * null or empty, returns `$default` (or throws "missing <type>" when no
     * default was provided). Otherwise pulls the types via `$typesLoader`
     * (so the cache is only touched when an actual lookup is needed) and
     * resolves through `matchType`.
     *
     * @param Closure(): list<WorkItemType> $typesLoader
     */
    public static function resolveType(string $cmd, ?string $input, ?string $default, Closure $typesLoader): string
    {
        if ($input === null || $input === '') {
            if ($default === null) {
                throw new RuntimeException("{$cmd}: missing <type>");
            }

            return $default;
        }

        return self::matchType($cmd, $input, $typesLoader());
    }

    /** @param list<WorkItemType> $types */
    public static function matchType(string $cmd, string $input, array $types): string
    {
        $allowed = array_values(array_filter(
            $types,
            static fn(WorkItemType $t): bool => in_array($t->name, self::ALLOWED_TYPES, true),
        ));
        $needle = mb_strtolower($input);
        foreach ($allowed as $type) {
            if (mb_strtolower($type->name) === $needle) {
                return $type->name;
            }
        }
        $matches = [];
        foreach ($allowed as $type) {
            if (str_starts_with(mb_strtolower($type->name), $needle)) {
                $matches[] = $type->name;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new RuntimeException("{$cmd}: ambiguous type '{$input}', could be: " . implode(', ', $matches));
        }
        $names = [];
        foreach ($allowed as $t) {
            $names[] = $t->name;
        }
        throw new RuntimeException("{$cmd}: unknown type '{$input}'. Allowed: " . implode(', ', $names));
    }

    /**
     * Resolves a CLI subcommand by case-insensitive exact match, falling back
     * to a unique case-insensitive prefix. Returns the canonical name on
     * unique resolve, throws on ambiguous prefix (caller surfaces the help),
     * returns null on no match.
     *
     * @param list<string> $commands
     */
    public static function matchCommand(string $input, array $commands): ?string
    {
        $needle = mb_strtolower($input);
        foreach ($commands as $cmd) {
            if (mb_strtolower($cmd) === $needle) {
                return $cmd;
            }
        }
        $matches = [];
        foreach ($commands as $cmd) {
            if (str_starts_with(mb_strtolower($cmd), $needle)) {
                $matches[] = $cmd;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new RuntimeException("Ambiguous command '{$input}', could be: " . implode(', ', $matches));
        }

        return null;
    }
}

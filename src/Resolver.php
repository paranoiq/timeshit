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
use function preg_replace;
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
    /** @param array<int, string> $argv */
    public static function restArgs(array $argv): ?string
    {
        $rest = array_slice($argv, 2);
        if ($rest === []) {
            return null;
        }

        return implode(' ', $rest);
    }

    /**
     * Peels off an optional leading `#<id>` token (literal `#` plus one or
     * more digits) from `$arg` and returns `[(int) id | null, remaining-string | null]`.
     * The id token must be separated from the rest by whitespace.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function peelRecordId(string $cmd, ?string $arg): array
    {
        if ($arg === null || $arg === '' || $arg[0] !== '#') {
            return [null, $arg];
        }
        if (preg_match('/^#(\d+)(?:\s+(.*))?$/', $arg, $m) !== 1) {
            throw new RuntimeException("{$cmd}: invalid record id token at start of '{$arg}' (expected #<digits>)");
        }
        $rest = $m[2] ?? '';

        return [(int) $m[1], $rest === '' ? null : $rest];
    }

    /**
     * Resolves a user-provided `<issue>` argument. Pure digits are expanded
     * with `$defaultPrefix` (e.g. `123` → `SW-123`). Standard `ABC-123` form
     * is uppercased. Anything else is returned verbatim — the format is not
     * enforced so users can track time against issues that don't yet exist
     * in YouTrack; the caller is expected to warn for non-standard ids
     * (see `isStandardIssueId`).
     */
    public static function requireIssueId(string $cmd, ?string $issue, string $defaultPrefix): string
    {
        if ($issue === null || $issue === '') {
            throw new RuntimeException("{$cmd}: missing <issue>");
        }
        if (preg_match('/^\d+$/', $issue) === 1) {
            return strtoupper($defaultPrefix . $issue);
        }
        if (preg_match('/^[A-Za-z]+-\d+$/', $issue) === 1) {
            return strtoupper($issue);
        }

        return $issue;
    }

    public static function isStandardIssueId(string $id): bool
    {
        return preg_match('/^[A-Za-z]+-\d+$/', $id) === 1;
    }

    /**
     * Pulls the YouTrack-style issue id out of a git branch name. Matches
     * `ABC-123…` at the start of the branch. If the branch starts with bare
     * digits and `$defaultPrefix` is non-empty, the prefix is prepended
     * (`1234-feature` → `SW-1234`).
     */
    public static function extractIssueId(string $branch, string $defaultPrefix = ''): string
    {
        if (preg_match('/^([A-Za-z]{1,3}-\d+)\b/', $branch, $m) === 1) {
            return strtoupper($m[1]);
        }
        if ($defaultPrefix !== '' && preg_match('/^(\d+)\b/', $branch, $m) === 1) {
            return strtoupper($defaultPrefix . $m[1]);
        }

        return $branch;
    }

    /**
     * Parses a YouTrack-style duration like `1h 20m`, `30m`, `2h`, `1d 4h 15m`
     * into a strictly positive number of minutes. Components must appear in
     * `d h m` order (any subset). Whitespace is ignored, case is ignored.
     */
    public static function parseSpan(string $cmd, ?string $input): int
    {
        if ($input === null || $input === '') {
            throw new RuntimeException("{$cmd}: missing <span>");
        }
        $cleaned = preg_replace('/\s+/', '', $input);
        if ($cleaned === null || $cleaned === '') {
            throw new RuntimeException("{$cmd}: invalid span '{$input}' (expected e.g. '1h 20m')");
        }
        if (preg_match('/^(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?$/i', $cleaned, $m) !== 1) {
            throw new RuntimeException("{$cmd}: invalid span '{$input}' (expected e.g. '1h 20m')");
        }
        $days = (int) ($m[1] ?? '');
        $hours = (int) ($m[2] ?? '');
        $mins = (int) ($m[3] ?? '');
        $total = $days * 24 * 60 + $hours * 60 + $mins;
        if ($total <= 0) {
            throw new RuntimeException("{$cmd}: invalid span '{$input}' (must be > 0)");
        }

        return $total;
    }

    /**
     * Resolves a `<time>` argument used by `at`. Bare `HH:MM` or `H:MM` keeps
     * the date of `$existingDateTime`; anything else is parsed by
     * `DateTimeImmutable`.
     */
    public static function resolveTime(string $cmd, ?string $input, string $existingDateTime): DateTimeImmutable
    {
        if ($input === null || $input === '') {
            throw new RuntimeException("{$cmd}: missing <time>");
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $input, $m) === 1) {
            $h = (int) $m[1];
            $mn = (int) $m[2];
            if ($h > 23 || $mn > 59) {
                throw new RuntimeException("{$cmd}: invalid time '{$input}'");
            }
            try {
                $base = new DateTimeImmutable($existingDateTime);
            } catch (Exception) {
                throw new RuntimeException("{$cmd}: invalid existing time '{$existingDateTime}'");
            }

            return $base->setTime($h, $mn);
        }
        try {
            return new DateTimeImmutable($input);
        } catch (Exception) {
            throw new RuntimeException("{$cmd}: invalid time '{$input}'");
        }
    }

    public static function resolveDate(?string $input, string $cmd = 'day', ?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $today ??= new DateTimeImmutable('today');
        $original = $input === null || $input === '' ? 'today' : $input;
        if (preg_match('/^\d+$/', $original) === 1) {
            return self::dayOfCurrentMonth((int) $original, $original, $cmd, $today);
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
            return $today->modify($offsets[$matches[0]] . ' days');
        }
        if (count($matches) > 1) {
            throw new RuntimeException("{$cmd}: ambiguous date '{$original}', could be: " . implode(', ', $matches));
        }
        try {
            return new DateTimeImmutable($original);
        } catch (Exception) {
            throw new RuntimeException("{$cmd}: invalid date '{$original}'");
        }
    }

    private static function dayOfCurrentMonth(int $day, string $original, string $cmd, DateTimeImmutable $today): DateTimeImmutable
    {
        $year = (int) $today->format('Y');
        $month = (int) $today->format('m');
        $resolved = $today->setDate($year, $month, $day);
        if ((int) $resolved->format('j') !== $day || (int) $resolved->format('n') !== $month) {
            throw new RuntimeException("{$cmd}: invalid day-of-month '{$original}' for the current month");
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
     * @param list<string> $allowedNames
     * @param array<string, list<string>> $aliases canonical type name => list of aliases
     */
    public static function resolveType(string $cmd, ?string $input, ?string $default, Closure $typesLoader, array $allowedNames, array $aliases = []): string
    {
        if ($input === null || $input === '') {
            if ($default === null) {
                throw new RuntimeException("{$cmd}: missing <type>");
            }

            return $default;
        }

        return self::matchType($cmd, $input, $typesLoader(), $allowedNames, $aliases);
    }

    /**
     * Resolves `$input` to a canonical type name. Both canonical names and
     * their aliases participate in matching: case-insensitive exact match
     * wins; otherwise case-insensitive prefix match across canonical names
     * and aliases. Multiple distinct canonicals reachable via prefix → error.
     * Aliases are surfaced in the "Allowed" error message as `canonical (alias1, alias2)`.
     *
     * @param list<WorkItemType> $types
     * @param list<string> $allowedNames
     * @param array<string, list<string>> $aliases canonical type name => list of aliases
     */
    public static function matchType(string $cmd, string $input, array $types, array $allowedNames, array $aliases = []): string
    {
        $allowed = array_values(array_filter(
            $types,
            static fn(WorkItemType $t): bool => in_array($t->name, $allowedNames, true),
        ));
        // Build a list of [label, canonical] candidates. Each canonical name
        // is a candidate, and so is every alias belonging to it.
        $candidates = [];
        foreach ($allowed as $type) {
            $candidates[] = ['label' => $type->name, 'canonical' => $type->name];
            foreach ($aliases[$type->name] ?? [] as $alias) {
                $candidates[] = ['label' => $alias, 'canonical' => $type->name];
            }
        }
        $needle = mb_strtolower($input);
        $exact = [];
        foreach ($candidates as $c) {
            if (mb_strtolower($c['label']) === $needle) {
                $exact[$c['canonical']] = true;
            }
        }
        $exactKeys = array_keys($exact);
        if (count($exactKeys) === 1) {
            return $exactKeys[0];
        }
        if (count($exactKeys) > 1) {
            throw new RuntimeException("{$cmd}: ambiguous type '{$input}', could be: " . implode(', ', $exactKeys));
        }
        $prefix = [];
        foreach ($candidates as $c) {
            if (str_starts_with(mb_strtolower($c['label']), $needle)) {
                $prefix[$c['canonical']] = true;
            }
        }
        $prefixKeys = array_keys($prefix);
        if (count($prefixKeys) === 1) {
            return $prefixKeys[0];
        }
        if (count($prefixKeys) > 1) {
            throw new RuntimeException("{$cmd}: ambiguous type '{$input}', could be: " . implode(', ', $prefixKeys));
        }
        $names = [];
        foreach ($allowed as $t) {
            $aliasList = $aliases[$t->name] ?? [];
            $names[] = $aliasList === [] ? $t->name : "{$t->name} (" . implode(', ', $aliasList) . ')';
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

        // not included in shortenable commands
        if ($needle === 'types') {
            return $needle;
        }

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

<?php declare(strict_types=1);

namespace Timeshit;

/**
 * Display + sort metadata for a single YouTrack issue state. Populated from
 * `config.neon`'s `issueStates:` block; unknown states fall through with
 * `priority = 0` and no color.
 *
 * Issue state names are normalized to their canonical (short) form at the
 * YouTrack boundary via `config.neon`'s `stateAliases:` map, so the keys in
 * `issueStates:` — and every state name flowing through the app — are always
 * the short form.
 *
 * - `priority` — sort key used by views (lower = more attention; 0 = unranked).
 *   By convention, finished/closed states sit at priority `99` — `IssuesView`
 *   uses that value to gate the `mineActive` filter and the "before-finished"
 *   rule line.
 * - `color` — Ansi method name (e.g. `red`, `lgreen`); `''` = no color.
 */
final class IssueState
{
    public function __construct(
        public readonly int $priority,
        public readonly string $color = '',
    ) {}
}
<?php declare(strict_types=1);

namespace Timeshit;

/**
 * A user-defined shortcut command from `config.neon`. Wraps a parent
 * command with pre-filled defaults so the user can type `analyse SW-1234`
 * instead of `track SW-1234 'Analyses / Design'`.
 *
 * - `name` — the command keyword (e.g. `analyse`, `meeting`)
 * - `parent` — any action command: `track`, `interrupt`, `put`, `grab`,
 *   `days`, `pause`, `resume`, `continue`, `done`, `end`, `switch`, `skip`.
 * - `type` — work-item type. Required for parents that record a type
 *   (`track`, `interrupt`, `put`, `grab`, `days`, `switch`). Ignored for
 *   parents that don't (`pause`, `resume`, `done`, `end`, `skip`).
 * - `issue` — optional pre-set issue id. When non-empty, the command does
 *   not need `<issue>` on the CLI (the meeting/mail pattern).
 * - `note` — optional default note. Always applied; when the user also
 *   passes a CLI note, the two are joined with ` | ` (default first, CLI
 *   detail trailing).
 * - `span` — optional default duration (e.g. `"30m"`, `"1h"`) for `put` /
 *   `grab` / `skip` parents.
 * - `day` — optional default date (e.g. `"yesterday"`) for `days` parent;
 *   pre-fills the single-day case (user passes 0 date args).
 */
final class CustomCommand
{
    public function __construct(
        public readonly string $name,
        public readonly string $parent,
        public readonly string $type = '',
        public readonly string $issue = '',
        public readonly string $note = '',
        public readonly string $span = '',
        public readonly string $day = '',
    ) {}
}
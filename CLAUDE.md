# timeshit

A simple time tracker for personal use, integrated with YouTrack, GitLab, and Git.

## Stack

- Plain PHP (no framework)
- Plain HTML, CSS (no JS framework)
- PHP's built-in development server (`php -S`) for the local server

## Components

### Local server
- Runs on PHP's internal server
- Receives events from a browser plugin / favelet (e.g. when viewing a YouTrack issue or GitLab MR)
- Likely also serves a simple web UI for viewing/editing tracked time

### CLI
- Tracks task switches automatically when the user switches Git branches or directories
- Provides simple `start` / `end` work commands
- Likely intended to be hooked into shell (cd hook, git hooks, or prompt integration)

### Browser plugin / favelet
- Sends events to the local server when the user navigates to relevant pages (YouTrack issues, GitLab MRs, etc.)

## Integrations

- **YouTrack** — issue/task identification
- **GitLab** — MR / project context
- **Git** — branch and repo as task signal

## Project layout

```
timeshit.php            CLI entry point — runs `composer install` if `vendor/autoload.php` is missing, then `require`s it and `exit((new App(__DIR__))->run($argv));`
config/                 NEON config (`config.neon` committed, `secrets.neon` gitignored)
  config.neon           committed — non-secret keys: `youtrackBaseUrl`, `timezone`
  secrets.neon          gitignored — single key: `youtrackToken`
src/                    flat under `Timeshit\` by default; orthogonal concerns live in sub-namespaces (`Timeshit\Local`, `Timeshit\View`, `Timeshit\Youtrack`)
  Ansi.php              ANSI 16-color helpers (red(), lred(), ...) + `length()` for width measurement
  App.php               CLI dispatcher — `App::run(array $argv): int` matches subcommand to private `cmd*` handlers; owns data file paths via class consts derived from `$rootDir`. Auto-runs the hidden `configure` command when `config/secrets.neon` is missing.
  Config.php            loads `config/config.neon` (timezone + base URL) and `config/secrets.neon` (`youtrackToken`)
  Format.php            shared display helpers (state/spent/roles/assignee/category/duration)
  Resolver.php          pure static helpers that turn user CLI input into canonical values: dates (resolveDate), times (resolveTime), durations/offsets (parseOffset), issue ids (requireIssueId/extractIssueId), work-item types (matchType + resolveType + ALLOWED_TYPES whitelist), command names (matchCommand), trailing argv (restArgs)
  Local/                `Timeshit\Local` — locally-tracked time entries not yet synced to YouTrack
    Record.php          immutable value object for a single tracked record (open or closed); carries optional `origStartedAt` / `origEndedAt` to preserve the first-recorded values when `at` / `before` / `after` rewrite times
    Store.php           append-only store for `Record`s (data/records.neon)
  Youtrack/             `Timeshit\Youtrack` — REST client, DTOs and on-disk caches scoped to YouTrack
    YoutrackClient.php  cURL-based YouTrack REST client (parses JSON into value objects)
    Issue.php           immutable value object representing a single YouTrack issue
    WorkItem.php        immutable value object representing a single time-tracking entry
    WorkItemType.php    immutable value object for a YouTrack work-item type (id + name)
    IssueCache.php      on-disk NEON cache for issue lists, mtime-based TTL
    WorkItemCache.php   on-disk NEON cache for work items, mtime-based TTL
    WorkItemTypeCache.php on-disk NEON cache for the YouTrack work-item-type list, mtime-based TTL
  View/                 `Timeshit\View` — terminal renderers for the CLI subcommands
    IssuesView.php      renders the issues table for `timeshit.php issues`
    WorkView.php        renders the work-items list with day/week rollups for `timeshit.php work`
    RecordsView.php     renders locally-tracked records with day/week rollups for `timeshit.php records`
hooks/                  committed — git hook templates
  post-checkout         calls `ts checkout <branch> <repo>` on every branch checkout
data/                   gitignored — runtime cache
  issues.neon           cached issue list with per-issue role tags (refreshed every 24h)
  work-items.neon       cached time-tracking entries authored by the current user
  work-item-types.neon  cached global list of YouTrack work-item types (id + name)
  records.json          locally-tracked time records; appended to by `track` / `checkout`, not yet synced
composer.json           runtime dep: nette/neon (cache + record store format); dev deps: phpstan, phpstan-strict-rules, nette/tester; PSR-4 Timeshit\ → src/
phpstan.neon            level 7 + strict rules, paths: src/ + timeshit.php
```

## CLI

Entry script `timeshit.php` at the project root is a thin wrapper: it `require`s the `src/` files and calls `(new Timeshit\App(__DIR__))->run($argv)`. All command logic lives in `Timeshit\App` (`src/App.php`). Subcommands as positional argument:

- `php timeshit.php` — usage
- `php timeshit.php issues` — list YouTrack issues; uses cached `data/issues.neon` if it is less than 24h old, otherwise fetches and re-caches
- `php timeshit.php work` — list your work items grouped by ISO week and day with per-day, per-week and grand totals (uses the same cache)
- `php timeshit.php records` — list locally-tracked records from `data/records.neon` grouped by ISO week and day with per-day and per-week totals; each row shows the start/end clock times and the start/end triggers. Open records count current elapsed time and are highlighted. Issue titles are taken from `data/issues.neon` when present (no network fetch).
- `php timeshit.php refresh` — force-refresh all YouTrack caches (issues, work items, work-item types) and print only the stats about what was fetched; does not list issues
- `php timeshit.php track <issue> [<type>]` — manually switch local time tracking to `<issue>`. Issue must match `[A-Za-z]+-\d+` (uppercased verbatim, no extraction); other shapes are rejected with "invalid issue". Type defaults to `Implementation`. Closes the previous open record and opens a new one in `data/records.neon` with `repo=""`, `startTrigger="manual"`, and **no `branch` field at all**. No-op when the same issue/repo/type/branch is already open.
- `php timeshit.php day <issue> [<date>] [<type>]` — append a single closed 8h record (09:00–17:00) for `<issue>` on `<date>`; does not touch the currently open record (inserted just before it so the open-is-latest invariant holds). Refuses to create a second full-day record on the same calendar date — errors in red and exits with code 1 if one already exists (full-day records are identified by `startTrigger === 'day'`). Overlap with regular tracking records on the same day is allowed. `<type>` defaults to `Out of office` and, when provided, must match a known YouTrack work-item type (case-insensitive against `data/work-item-types.neon`; canonical casing is written back); auto-fetches the type list when the cache is missing or older than 24h. Both `startTrigger` and `endTrigger` are `"day"`; `repo=""` and no `branch` field. `<date>` defaults to today. Accepted forms for `<date>`: a plain integer = day-of-month in the current month (e.g. `15`); the keywords `today` / `yesterday` / `tomorrow` / `ereyesterday` (= 2 days ago) / `overmorrow` (= 2 days ahead), case-insensitive and matched as a unique prefix (e.g. `yes`, `over`, `tod`, `tom`, `ere` all work; `t` / `to` are rejected as ambiguous); or anything `DateTimeImmutable` understands (e.g. `2026-05-08`) when no keyword prefix matches.

All CLI error output is rendered in red on STDERR via `Ansi::red`; exit code is `1`.

## Command name resolution

The dispatcher resolves the subcommand the same way `<type>` and `<date>` keywords are resolved: case-insensitive exact match wins, otherwise unique case-insensitive prefix. So `ts sw` runs `switch`, `ts sk` runs `skip`, `ts st` runs `steal`, `ts rec` runs `records`, `ts ref` runs `refresh`, `ts ch` runs `checkout`, `ts b` runs `before`, `ts af` runs `after`. Exact matches always win even when the name is also a prefix of another command (e.g. `ts at` runs `at`, not the ambiguous `at`/`after` prefix). Ambiguous prefixes (`t` → types/track/type, `r` → records/refresh/resume, `c` → comment/checkout/configure, `s` → switch/skip/steal, `a` → at/after) error in red ("Ambiguous command 't', could be: …") and print the help. Unknown input falls through to the existing "Unknown command" + help path. `help`, `-h`, and `--help` keep their special early-return behavior; `h`, `he`, `hel`, `help` all also resolve via prefix matching.

## Type matching

Every command that takes a `<type>` argument (`track`, `day`, `type`, `switch`) resolves it through the same `Resolver::matchType` helper. We currently constrain the cached YouTrack types to a hard-coded whitelist (`Resolver::ALLOWED_TYPES`); only those six are eligible to match:

- `Analyses / Design`
- `Communication, Meetings, ...`
- `Documentation`
- `Implementation`
- `Out of office`
- `Test / Review`

`matchType` filters the input list down to those names, then:

1. Case-insensitive **exact** match wins.
2. Otherwise, case-insensitive **unique prefix** match (e.g. `imp` → `Implementation`, `ou` → `Out of office`, single letters `a` / `c` / `d` / `i` / `o` / `t` are each unique).
3. Multiple prefix matches → error listing the candidates (rare with the current 6-type whitelist since each starts with a unique letter).
4. No match at all → error listing the **allowed** types only.

The whitelist also drives the `types` list view (`php timeshit.php types`), where allowed names are rendered in green and the rest in default color, so you can see at a glance which types the local commands will accept.

Whatever the input, the canonical casing from the cache is what gets written to the record. `track` and `day` accept the type as optional (default `Implementation` and `Out of office` respectively); `type` and `switch` require it. `Resolver::resolveType($cmd, $input, $default, $typesLoader)` wraps the default/missing handling around `matchType` and takes a `Closure(): list<WorkItemType>` for the cache so the lookup is only triggered when an actual match is needed (i.e. the cache is *not* loaded when the default applies).
- `php timeshit.php checkout <branch> <repo>` — same as `track` but with a required `<repo>` argument and `startTrigger="checkout"`; type always defaults to `Implementation` (the hook doesn't pass one). Intended to be invoked by `hooks/post-checkout`, not by hand.
- `php timeshit.php type <type>` — change the type of the currently open `records.json` record in place (preserves `startedAt`). The canonical casing from `data/work-item-types.neon` is written back. Errors when no entry is open or `<type>` is unknown. Auto-fetches the type list from YouTrack (`/api/admin/timeTrackingSettings/workItemTypes`) when the cache is missing or older than 24h. See *Type matching* below for how `<type>` is resolved.
- `php timeshit.php types` — list the YouTrack work-item types (name + id) from `data/work-item-types.neon`, auto-fetching when the cache is missing or older than 24h.
- `php timeshit.php switch <type>` — end the currently open entry and append a new one cloned from it (same `issueId`/`branch`/`repo`) with the given `<type>`; both `endTrigger` of the closed segment and `startTrigger` of the new one are recorded as `switched`. Same matching/validation rules as `type`. No-op when `<type>` already matches; errors when no entry is open. Use this when you want each typed segment to be its own row (intended for eventual sync to YouTrack as separate work items).
- `php timeshit.php pause [<comment>]` — close the currently open record with `endTrigger="paused"`. Optional `<comment>` is appended to the closed record's existing comment with ` | ` as separator. Errors when no entry is open.
- `php timeshit.php resume [<comment>]` — append a new open record cloned from the most recent closed record (same `issueId`/`branch`/`repo`/`type`), with `startTrigger="resumed"`. Optional `<comment>` becomes the new record's comment. Errors when no records exist or the latest record is already open.
- `php timeshit.php skip <offset>` — close the currently open record at `now - <offset>` and immediately open a new one cloned from it (same `issueId`/`branch`/`repo`/`type`) starting at `now`. Both `endTrigger` of the closed segment and `startTrigger` of the new one are recorded as `"skipped"`. Used when you forgot to `pause` during a break: the `<offset>` is the length of the unrecorded gap (e.g. `45m` for a lunch break). `<offset>` is parsed by `Resolver::parseOffset` (same `d`/`h`/`m` grammar as `before` / `after`). Errors when no entry is open or the offset would push the close time at or before the open record's `startedAt`.
- `php timeshit.php steal <issue> <offset> [<type>]` — like `skip`, but the `<offset>`-long hole is **filled** with a closed record for `<issue>` instead of left empty. Three records are written in one save: (1) the original open record closed at `now - <offset>` with `endTrigger="stolen"`; (2) a new closed record for `<issue>` from `now - <offset>` to `now` with `startTrigger="stolen"` / `endTrigger="stolen"`; (3) a continuation cloned from the original (same `issueId`/`branch`/`repo`/`type`) starting at `now` with `startTrigger="stolen"`. `<issue>` is validated by `Resolver::requireIssueId` (must match `[A-Za-z]+-\d+`); `<type>` follows `track`'s rules (default `Implementation`, optional case-insensitive resolution against `Resolver::ALLOWED_TYPES`). The stolen record has `repo=""` and no `branch` field (same shape as `track`). Used when you forgot to `track` an interruption: e.g. `ts steal XYZ-12 30m` after spending half an hour on a different ticket without switching. Same offset-too-large guard as `skip`.
- `php timeshit.php end [<comment>]` — close the currently open entry, recording `endTrigger="ended"`. When `<comment>` is provided it is appended to the entry's existing comment with ` | ` as separator. Errors when no entry is open.
- `php timeshit.php comment <text>` — append `<text>` to the currently open entry's `comment` with ` | ` as separator. No-op when the result would be unchanged (e.g. empty append); errors when no entry is open.
- `php timeshit.php at <time>` — set the timestamp of the **last non-day record** (records with `startTrigger="day"` are skipped). On an open record sets `startedAt`; on a closed record sets `endedAt`. `<time>` is `HH:MM` (1–2 digit hour, 2 digit minute — keeps the date of the existing timestamp) or any string `DateTimeImmutable` parses (e.g. `2026-05-09 10:00`). Prints an Old/New diff and waits for `[y/N]` confirmation; saves on `y`, exits without writing on anything else. Errors when there is no eligible record, when the result would have non-positive duration, or when the time can't be parsed.
- `php timeshit.php before <offset>` — same target/confirmation flow as `at`, but shifts the relevant timestamp **earlier** by `<offset>` (open record → `startedAt`; closed → `endedAt`).
- `php timeshit.php after <offset>` — shifts the `endedAt` of the last non-day record **later** by `<offset>`. Errors when the last non-day record is open (use `before` / `at` to move its start, or close it first).
- `php timeshit.php configure` (hidden — not listed in `--help`) — interactive prompts for `youtrackBaseUrl`, `timezone`, and `youtrackToken`; writes `config/config.neon` and `config/secrets.neon` via `Neon::encode`. Existing values appear as `[default]` hints (just press Enter to keep them); for the token the hint is `[press Enter to keep existing]`. Auto-triggered by `App::run()` when `config/secrets.neon` is missing (so the first run on a fresh checkout is self-bootstrapping); can also be invoked explicitly to re-configure later.

`<offset>` (used by `before` / `after`) is a YouTrack-style duration: components `d` / `h` / `m` in that order, any subset, whitespace and case-insensitive (`30m`, `1h`, `1h 20m`, `1h30m`, `1d 4h 15m`, `1H 20M` all work). Out-of-order components (e.g. `20m 1h`) and zero totals (`0m`) are rejected. Resolved through `Resolver::parseOffset` (returns total minutes).

When `at` / `before` shifts the **open** record's `startedAt` and the immediately-prior record's `endedAt` matched the **old** `startedAt` (and that prior record is not a day record), the prior record's `endedAt` is shifted to match the new value too — so adjacent segments stay adjacent. Both records appear in the Old/New diff. The first time a record's `startedAt` or `endedAt` is rewritten, the previous value is captured into `origStartedAt` / `origEndedAt`; subsequent rewrites do **not** overwrite the original (so the very first canonical timestamp is always recoverable).

Future commands (start, end, etc.) follow the same `timeshit.php <command>` pattern.

CLI entry-point scripts live at the project root (no `bin/` directory). PHP 8.2+ (per composer.json). `timeshit.php` loads `vendor/autoload.php` and dispatches to `App` — composer's PSR-4 (`Timeshit\` → `src/`) handles all class loading, plus the runtime dep on `nette/neon`.

## Static analysis

PHPStan level 7 with `phpstan-strict-rules` must pass. `phpstan.neon` covers `src/`, `tests/`, and `timeshit.php`, with `phpt` added to `fileExtensions` so test files are scanned. Run:

```
vendor/bin/phpstan analyse
```

## Tests

Unit tests live under `tests/` and run on [nette/tester](https://github.com/nette/tester) (dev dependency). Test files use the `.phpt` extension and load `vendor/autoload.php`. Run:

```
vendor/bin/tester tests
```

Current coverage:
- `tests/DaySelection.phpt` exercises `Resolver::resolveDate()` (the date expression for `php timeshit.php day`) — keyword exact match, unique prefix, ambiguous prefix, case-insensitivity, integer day-of-month, ISO date fallthrough, and invalid input.
- `tests/TypeMatching.phpt` exercises `Resolver::matchType()` (used by every command that takes a `<type>` argument) — case-insensitive exact match, unique-prefix resolution, the `Resolver::ALLOWED_TYPES` whitelist filtering (disallowed types like `Investigation`/`Internal Meetings` are invisible even on exact match), unknown-type errors that list only the allowed types, and the `<cmd>:` prefix in error messages.
- `tests/OffsetParsing.phpt` exercises `Resolver::parseOffset()` (the `<offset>` argument for `before` / `after`) — single units, combined `d`/`h`/`m` with and without spaces, case-insensitivity, empty/missing input, zero-total rejection, garbage rejection, and the requirement that components appear in `d`-`h`-`m` order (`20m 1h` is rejected). Error messages preserve the `<cmd>:` prefix.
- `tests/TimeResolution.phpt` exercises `Resolver::resolveTime()` (the `<time>` argument for `at`) — bare `HH:MM` keeping the date of the existing timestamp (and zeroing sub-minute precision), 1-digit hour acceptance, out-of-range rejection (`24:00` / `12:60`), full-datetime fallthrough via `DateTimeImmutable`, empty/missing input, and garbage rejection. Error messages preserve the `<cmd>:` prefix.

Type-narrowing rules learned the hard way:
- API responses (decoded JSON) are typed as `array<int|string, mixed>` at the boundary. Narrowing to a specific shape happens once, in the client, by parsing into a value object (`YoutrackIssue`). Do not chain `is_array` / `is_string` checks at every call site — push the narrowing into a parser method.
- For `cURL` calls, `curl_init()` can return `false` (must be checked); `curl_getinfo($ch, CURLINFO_HTTP_CODE)` is already typed `int` in stubs (do not cast or `is_int`-check).
- Don't use `@phpstan-ignore`, baseline entries, inline `@var` to override inferred types, `assert()`, or pointless casts to silence errors. Fix the underlying type.

## Conventions

- Secrets live in `config/secrets.neon` (gitignored, single key `youtrackToken`); non-secret config lives in `config/config.neon` (committed). `Config::load($root)` reads both for full config; `Config::timezone($root)` reads only the public file.
- HTTP client wraps cURL; no Guzzle/Composer for now. Same interface can later be swapped for Guzzle if needed.
- "Current user" in YouTrack queries is implicit via the token — we use `me` keyword (e.g. `assignee: me, commenter: me, ...`).
- YouTrack custom field shapes vary (User / Enum / Period / ...). The client returns raw decoded issues; callers extract via `Client::customFieldValue($issue, $name)` and interpret the shape themselves. Field names assumed: `State`, `Type`, `Assignee`, `Category`, `Spent time` — adjust if a project uses different names.
- To know *why* an issue was downloaded, the client runs one query per role (`assignee: me`, `commenter: me`, `reporter: me`, `updater: me`, `tag: Star`, `mentions: me`) plus a `/api/workItems?author=me` call, and merges results by issue ID. Each `YoutrackIssue` carries a `roles: list<string>` with the matching subset of `assignee` / `commenter` / `reporter` / `updater` / `workAuthor` / `starred` / `mentioned`.

## Local tracking

`data/records.neon` holds an append-only list of tracking records. Each record stores `issueId`, `repo`, `type`, `startedAt` / `startTrigger`, (once closed) `endedAt` / `endTrigger`, and the bookkeeping fields `createdAt` (set when the record is first written and never changes) and `modifiedAt` (bumped on every mutation: `withEnd` / `withType` / `withComment` / `withStartedAt` / `withEndedAt`). Records that were rewritten by `at` / `before` / `after` also carry `origStartedAt` and/or `origEndedAt` — the very first value of the corresponding field, captured on the first rewrite and never overwritten afterwards (so subsequent edits stack onto the same `orig…` baseline). Both fields are omitted from the NEON when null, like `branch`. For most records `createdAt === startedAt`; the exception is the `day` command, where `startedAt` is 09:00 of the chosen day while `createdAt` is when the user actually ran the command. Records originating from `checkout` also carry `branch` (the git branch name); records from `track` (manual) and `day` omit `branch` from the NEON entirely. Timestamps are stored in the timezone configured in `config/config.neon` (`timezone: …`) and formatted as `Y-m-d H:i` — no offset suffix, space (not `T`) between date and time, no seconds (e.g. `2026-05-09 09:59`). `App::run()` calls `date_default_timezone_set(Config::timezone($rootDir))` once per command (after the help-only early return) so every subsequent `date(…)` / `new DateTimeImmutable(…)` lives in that timezone — including the `setTime(9, 0)` / `setTime(17, 0)` calls in `day`. Pre-existing records that lack `createdAt` / `modifiedAt` are loaded with both falling back to `startedAt`, and the next mutation rewrites them with real values. The latest record is the only one allowed to be open (`endedAt: null`). The `track` (manual) and `checkout` (git hook) subcommands both close that open record and open a new one in a single write; concurrent shells therefore race, but in practice branch checkouts are serial. Not yet synced to YouTrack.

To install the post-checkout hook in another repo:

```
ln -s /home/vlasta/dev/php/timeshit/hooks/post-checkout <repo>/.git/hooks/post-checkout
```

Or set globally for all repos: `git config --global core.hooksPath /home/vlasta/dev/php/timeshit/hooks`. The hook expects `ts` on `PATH` (a script, not a shell alias — git hooks run non-interactively and do not source rc files).

## Status

- YouTrack: read-only — list issues the current user is assigned to / reported / commented on / last updated / has logged work on / starred / mentioned in, with per-issue role tags. Also pulls all work items the current user authored, and the global work-item-type list. 24h NEON caches at `data/issues.neon`, `data/work-items.neon`, and `data/work-item-types.neon`. Run with `php timeshit.php issues` (cached), `php timeshit.php work` (work items by week/day), or `php timeshit.php refresh` (force-refresh issues, work items, and types).
- Local server: not started.
- CLI: branch-switch tracking via `checkout` (called from `hooks/post-checkout`) and manual switching via `track` both write to `data/records.neon`. `records` displays the local log grouped by week/day with start/end times and triggers. After-the-fact editing via `at` / `before` / `after` is wired up (with adjacency adjustment for the previous record, and `origStartedAt` / `origEndedAt` preserving the first-recorded values). First-run bootstrapping is handled by `timeshit.php` (auto `composer install`) and the hidden `configure` command (auto-triggered when `config/secrets.neon` is missing). Sync to YouTrack not started.
- GitLab integration: not started.
- Browser plugin / favelet: not started.

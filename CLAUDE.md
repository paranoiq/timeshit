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
timeshit.php            CLI entry point — runs `composer install` if `vendor/autoload.php` is missing, then bootstraps: `--help` / no-args calls `App::printHelp(new StdIo())`; missing `config/secrets.neon` runs `Configurator` directly; otherwise loads `Config` and dispatches via `App::forRoot($rootDir, $config, new StdIo())->run($argv)`.
config/                 NEON config (`config.neon` committed, `secrets.neon` gitignored)
  config.neon           committed — non-secret keys: `youtrackBaseUrl`, `timezone`, `allowedTypes`
  secrets.neon          gitignored — single key: `youtrackToken`
src/                    flat under `Timeshit\` by default; orthogonal concerns live in sub-namespaces (`Timeshit\Local`, `Timeshit\Util`, `Timeshit\View`, `Timeshit\Youtrack`)
  App.php               CLI dispatcher — `App::run(array $argv): int` matches subcommand to private `cmd*` handlers. Constructor takes injected deps: `Config`, `Local\RecordStore`, `Youtrack\TypeProvider`, `Youtrack\IssueDataProvider`, `Util\Clock`, `Util\Io`, `Configurator`. `App::forRoot($rootDir, $config, $io)` is the production factory that wires the file/network-backed impls. `App::printHelp(Io $io)` is static so the bootstrap can call it without a `Config`.
  Config.php            value object holding `youtrackBaseUrl`, `youtrackToken`, `timezone`, `allowedTypes` (the list<string> whitelist of YouTrack work-item types accepted on local records). `Config::load($root)` reads `config/config.neon` (base URL + timezone + allowedTypes) and `config/secrets.neon` (`youtrackToken`); the public constructor lets tests build one without disk. `Config::timezone($root)` reads only the public file.
  Configurator.php      interactive first-run / reconfigure flow; takes `$rootDir` + `Util\Io`. Used both by the bootstrap in `timeshit.php` (when `config/secrets.neon` is missing) and by the explicit `php timeshit.php configure` subcommand.
  Format.php            shared display helpers (state/spent/roles/assignee/category/duration)
  Resolver.php          pure static helpers that turn user CLI input into canonical values: dates (resolveDate), times (resolveTime), durations/offsets (parseOffset), issue ids (requireIssueId/extractIssueId), work-item types (matchType + resolveType — both take an explicit `list<string> $allowedNames` whitelist supplied by the caller from `Config::$allowedTypes`), command names (matchCommand), trailing argv (restArgs)
  Local/                `Timeshit\Local` — locally-tracked time entries not yet synced to YouTrack
    Record.php          immutable value object for a single tracked record (open or closed); each record carries an auto-increment integer `id` (assigned by `RecordStore::nextId()`) so it can be referenced independently of the non-unique issue id and its position in the list. Also carries optional `origStartedAt` / `origEndedAt` to preserve the first-recorded values when `at` / `before` / `after` rewrite times.
    RecordStore.php     interface for the append-only record store: `load` / `nextId` / `save` / `track` / `appendClosed` / `changeOpenType` / `endOpen` / `commentLast`. `nextId()` returns and bumps the auto-increment counter (callers that mint multiple records in one transaction call it once per record). Mutators that bump `modifiedAt` take it as a parameter — no hidden `date()` calls inside the store.
    FileRecordStore.php production impl — append-only NEON store at `data/records.neon`
    InMemoryRecordStore.php test impl — keeps records in memory; same mutation semantics as the file impl
  Util/                 `Timeshit\Util` — small reusable utilities + injectable abstractions for time and IO
    Ansi.php            ANSI 16-color helpers (red(), lred(), ...) + `length()` for width measurement
    Clock.php           interface — `now(): DateTimeImmutable` and `nowMinute(): string` (`Y-m-d H:i`)
    SystemClock.php     production clock (wraps `new DateTimeImmutable()`)
    FixedClock.php      test clock — `set($time)` / `advance($modifier)` for deterministic timelines
    Io.php              interface — `out(string)`, `err(string)`, `readLine(): ?string`
    StdIo.php           production IO (writes to STDOUT/STDERR, reads from STDIN)
    BufferedIo.php      test IO — captures `out` / `err` strings; `setInputs([...])` queues `readLine()` answers (e.g. feed `'y'` to auto-confirm `before` / `after` / `at`)
  Youtrack/             `Timeshit\Youtrack` — REST client, DTOs, on-disk caches and provider interfaces scoped to YouTrack
    YoutrackClient.php  cURL-based YouTrack REST client (parses JSON into value objects)
    Issue.php           immutable value object representing a single YouTrack issue
    WorkItem.php        immutable value object representing a single time-tracking entry
    WorkItemType.php    immutable value object for a YouTrack work-item type (id + name)
    IssueCache.php      on-disk NEON cache for issue lists, mtime-based TTL; `exists()` lets `titles()` read a stale cache without forcing a fetch
    WorkItemCache.php   on-disk NEON cache for work items, mtime-based TTL
    WorkItemTypeCache.php on-disk NEON cache for the YouTrack work-item-type list, mtime-based TTL
    TypeProvider.php    interface — `types(): list<WorkItemType>`, `refresh(): void`
    CachedTypeProvider.php production impl — wraps `WorkItemTypeCache` + `YoutrackClient`; auto-fetches on stale/missing cache
    StubTypeProvider.php test impl — returns a fixed list
    IssueDataProvider.php interface — `loadOrFetch(): array{user, issues, workItems}`, `refresh(): void`, `titles(): array<string, string>`
    CachedIssueDataProvider.php production impl — wraps `IssueCache` + `WorkItemCache` + `YoutrackClient`
    StubIssueDataProvider.php test impl — returns fixed data
  View/                 `Timeshit\View` — terminal renderers for the CLI subcommands
    IssuesView.php      renders the issues table for `timeshit.php issues`
    WorkView.php        renders the work-items list with day/week rollups for `timeshit.php pushed`
    RecordsView.php     renders locally-tracked records with day/week rollups for `timeshit.php local`
    AllView.php         renders the unified `timeshit.php all` view (synced YouTrack work items + local records, with a `●`/`○`/`✗` status column)
hooks/                  committed — git hook templates
  post-checkout         calls `ts checkout <branch> <repo>` on every branch checkout
data/                   gitignored — runtime cache
  issues.neon           cached issue list with per-issue role tags (refreshed every 24h)
  work-items.neon       cached time-tracking entries authored by the current user
  work-item-types.neon  cached global list of YouTrack work-item types (id + name)
  records.neon          locally-tracked time records; appended to by `track` / `checkout`, not yet synced
composer.json           runtime dep: nette/neon (cache + record store format); dev deps: phpstan, phpstan-strict-rules, nette/tester; PSR-4 Timeshit\ → src/
phpstan.neon            level 8 + strict rules, paths: src/ + tests/ + timeshit.php; `src/Console.php` and `src/Os.php` are excluded for now (FFI/Windows console bindings — needs targeted stubs to clear at level 8)
```

## CLI

Entry script `timeshit.php` at the project root is a thin bootstrap: it `require`s `vendor/autoload.php`, handles the `--help` / no-args / first-run-`configure` paths directly (without needing a `Config`), and otherwise builds the production `App` via `App::forRoot($rootDir, $config, new StdIo())` and calls `->run($argv)`. All command logic lives in `Timeshit\App` (`src/App.php`). Subcommands as positional argument:

- `php timeshit.php` — usage
- `php timeshit.php status` — quick "what am I doing right now" view: prints the currently open record (if any) under `Active:` and the most recent closed record under `Previous:`. Day records (`startTrigger === 'day'`) are skipped — `status` is meant for the actual tracking flow, not full-day OOO entries. Each line shows the linked issue id, type, time range, and duration; `branch` is shown for `checkout`-triggered records. When the record's `issueId` matches an entry in `data/issues.neon`, the issue title is rendered on a second indented line (default color). The comment (when set) is rendered on its own indented line in dim color (lblack). Times shown as `HH:MM` for today's records, `Y-m-d H:i` otherwise. Prints `No tracking records.` when there are no non-day records at all, or `No active record.` when the latest non-day record is closed.
- `php timeshit.php issues` — list YouTrack issues; uses cached `data/issues.neon` if it is less than 24h old, otherwise fetches and re-caches
- `php timeshit.php pushed` — list time entries already pushed to YouTrack (the cached YouTrack work items authored by you), grouped by ISO week and day with per-day, per-week and grand totals (uses the same cache)
- `php timeshit.php local` — list locally-tracked records from `data/records.neon` grouped by ISO week and day with per-day and per-week totals; each row shows the start/end clock times and the start/end triggers. Open records count current elapsed time and are highlighted. Issue titles are taken from `data/issues.neon` when present (no network fetch).
- `php timeshit.php all` — combined view of `pushed` (YouTrack work items already synced) and `local` (records not yet synced), grouped by ISO week and day with per-day and per-week totals. Layout matches `pushed` — issue id, duration, type, title, comment — plus a one-character **status indicator** as the first column: `●` (green) = synced YouTrack work item, `○` (yellow) = local-only record, `✗` (red) = sync failed (placeholder; not produced yet — reserved for the upcoming sync flow where invalid issue ids or not-yet-created issues will land here). A legend with the three markers prints once at the top of the output. Sorted by date desc; within the same calendar day, work items appear before records (stable sort). Records contribute their duration via `endedAt - startedAt` (open records count elapsed up to `now`).
- `php timeshit.php refresh` — force-refresh all YouTrack caches (issues, work items, work-item types) and print only the stats about what was fetched; does not list issues
- `php timeshit.php track <issue> [<type>]` — manually switch local time tracking to `<issue>`. Issue must match `[A-Za-z]+-\d+` (uppercased verbatim, no extraction); other shapes are rejected with "invalid issue". Type defaults to `Implementation`. Closes the previous open record and opens a new one in `data/records.neon` with `repo=""`, `startTrigger="manual"`, and **no `branch` field at all**. No-op when the same issue/repo/type/branch is already open.
- `php timeshit.php day <issue> [<date>] [<type>]` — append a single closed 8h record (09:00–17:00) for `<issue>` on `<date>`; does not touch the currently open record (inserted just before it so the open-is-latest invariant holds). Refuses to create a second full-day record on the same calendar date — errors in red and exits with code 1 if one already exists (full-day records are identified by `startTrigger === 'day'`). Overlap with regular tracking records on the same day is allowed. `<type>` defaults to `Out of office` and, when provided, must match a known YouTrack work-item type (case-insensitive against `data/work-item-types.neon`; canonical casing is written back); auto-fetches the type list when the cache is missing or older than 24h. Both `startTrigger` and `endTrigger` are `"day"`; `repo=""` and no `branch` field. `<date>` defaults to today. Accepted forms for `<date>`: a plain integer = day-of-month in the current month (e.g. `15`); the keywords `today` / `yesterday` / `tomorrow` / `ereyesterday` (= 2 days ago) / `overmorrow` (= 2 days ahead), case-insensitive and matched as a unique prefix (e.g. `yes`, `over`, `tod`, `tom`, `ere` all work; `t` / `to` are rejected as ambiguous); or anything `DateTimeImmutable` understands (e.g. `2026-05-08`) when no keyword prefix matches.

All CLI error output is rendered in red on STDERR via `Ansi::red`; exit code is `1`.

## Command name resolution

The dispatcher resolves the subcommand the same way `<type>` and `<date>` keywords are resolved: case-insensitive exact match wins, otherwise unique case-insensitive prefix. So `ts sw` runs `switch`, `ts sk` runs `skip`, `ts ste` runs `steal`, `ts sta` runs `status`, `ts l` runs `local`, `ts al` runs `all`, `ts ref` runs `refresh`, `ts res` runs `resume`, `ts ch` runs `checkout`, `ts b` runs `before`, `ts af` runs `after`, `ts pa` runs `pause`, `ts pu` runs `pushed`. Exact matches always win even when the name is also a prefix of another command (e.g. `ts at` runs `at`, not the ambiguous `at`/`after`/`all` prefix). Ambiguous prefixes (`t` → types/track/type, `r` → refresh/resume, `c` → comment/checkout/configure, `s` → switch/skip/steal/status, `st` → steal/status, `p` → pause/pushed, `a` → at/after/all) error in red ("Ambiguous command 't', could be: …") and print the help. Unknown input falls through to the existing "Unknown command" + help path. `help`, `-h`, and `--help` keep their special early-return behavior; `h`, `he`, `hel`, `help` all also resolve via prefix matching.

## Type matching

Every command that takes a `<type>` argument (`track`, `day`, `type`, `switch`) resolves it through the same `Resolver::matchType` helper. We currently constrain the cached YouTrack types to a whitelist defined in `config/config.neon` under `allowedTypes` (loaded onto `Config::$allowedTypes` and passed into every `Resolver::matchType` / `Resolver::resolveType` call); only those six are eligible to match:

- `Analyses / Design`
- `Communication, Meetings, ...`
- `Documentation`
- `Implementation`
- `Out of office`
- `Test / Review`

`matchType` filters the input list down to the names supplied via `$allowedNames`, then:

1. Case-insensitive **exact** match wins.
2. Otherwise, case-insensitive **unique prefix** match (e.g. `imp` → `Implementation`, `ou` → `Out of office`, single letters `a` / `c` / `d` / `i` / `o` / `t` are each unique).
3. Multiple prefix matches → error listing the candidates (rare with the current 6-type whitelist since each starts with a unique letter).
4. No match at all → error listing the **allowed** types only.

The whitelist also drives the `types` list view (`php timeshit.php types`), where allowed names are rendered in green and the rest in default color, so you can see at a glance which types the local commands will accept.

Whatever the input, the canonical casing from the cache is what gets written to the record. `track` and `day` accept the type as optional (default `Implementation` and `Out of office` respectively); `type` and `switch` require it. `Resolver::resolveType($cmd, $input, $default, $typesLoader, $allowedNames)` wraps the default/missing handling around `matchType` and takes a `Closure(): list<WorkItemType>` for the cache so the lookup is only triggered when an actual match is needed (i.e. the cache is *not* loaded when the default applies); `App` passes `$this->config->allowedTypes` for `$allowedNames`.
- `php timeshit.php checkout <branch> <repo>` — same as `track` but with a required `<repo>` argument and `startTrigger="checkout"`; type always defaults to `Implementation` (the hook doesn't pass one). Intended to be invoked by `hooks/post-checkout`, not by hand.
- `php timeshit.php type <type>` — change the type of the currently open `records.json` record in place (preserves `startedAt`). The canonical casing from `data/work-item-types.neon` is written back. Errors when no entry is open or `<type>` is unknown. Auto-fetches the type list from YouTrack (`/api/admin/timeTrackingSettings/workItemTypes`) when the cache is missing or older than 24h. See *Type matching* below for how `<type>` is resolved.
- `php timeshit.php types` — list the YouTrack work-item types (name + id) from `data/work-item-types.neon`, auto-fetching when the cache is missing or older than 24h.
- `php timeshit.php switch <type>` — end the currently open entry and append a new one cloned from it (same `issueId`/`branch`/`repo`) with the given `<type>`; both `endTrigger` of the closed segment and `startTrigger` of the new one are recorded as `switched`. Same matching/validation rules as `type`. No-op when `<type>` already matches; errors when no entry is open. Use this when you want each typed segment to be its own row (intended for eventual sync to YouTrack as separate work items).
- `php timeshit.php pause [<comment>]` — close the currently open record with `endTrigger="paused"`. Optional `<comment>` is appended to the closed record's existing comment with ` | ` as separator. Errors when no entry is open.
- `php timeshit.php resume [<comment>]` — append a new open record cloned from the most recent closed record (same `issueId`/`branch`/`repo`/`type`), with `startTrigger="resumed"`. Optional `<comment>` becomes the new record's comment. Errors when no records exist or the latest record is already open.
- `php timeshit.php skip <offset>` — close the currently open record at `now - <offset>` and immediately open a new one cloned from it (same `issueId`/`branch`/`repo`/`type`) starting at `now`. Both `endTrigger` of the closed segment and `startTrigger` of the new one are recorded as `"skipped"`. Used when you forgot to `pause` during a break: the `<offset>` is the length of the unrecorded gap (e.g. `45m` for a lunch break). `<offset>` is parsed by `Resolver::parseOffset` (same `d`/`h`/`m` grammar as `before` / `after`). Errors when no entry is open or the offset would push the close time at or before the open record's `startedAt`.
- `php timeshit.php steal <issue> <offset> [<type>]` — like `skip`, but the `<offset>`-long hole is **filled** with a closed record for `<issue>` instead of left empty. Three records are written in one save: (1) the original open record closed at `now - <offset>` with `endTrigger="stolen"`; (2) a new closed record for `<issue>` from `now - <offset>` to `now` with `startTrigger="stolen"` / `endTrigger="stolen"`; (3) a continuation cloned from the original (same `issueId`/`branch`/`repo`/`type`) starting at `now` with `startTrigger="stolen"`. `<issue>` is validated by `Resolver::requireIssueId` (must match `[A-Za-z]+-\d+`); `<type>` follows `track`'s rules (default `Implementation`, optional case-insensitive resolution against `Config::$allowedTypes`). The stolen record has `repo=""` and no `branch` field (same shape as `track`). Used when you forgot to `track` an interruption: e.g. `ts steal XYZ-12 30m` after spending half an hour on a different ticket without switching. Same offset-too-large guard as `skip`.
- `php timeshit.php end [<comment>]` — close the currently open entry, recording `endTrigger="ended"`. When `<comment>` is provided it is appended to the entry's existing comment with ` | ` as separator. Errors when no entry is open.
- `php timeshit.php comment <text>` — append `<text>` to the **last non-day record's** `comment` with ` | ` as separator. The target is the open record when one exists, otherwise the most recent closed record (so notes can be attached after `pause` / `end`); day records (`startTrigger === 'day'`) are always skipped. The output line says `Comment on <issue> (active)` vs `(last closed)` so it's clear where the note landed. No-op when the result would be unchanged (e.g. empty append); errors with `no record to comment on` when no eligible record exists.
- `php timeshit.php at <time>` — set the timestamp of the **last non-day record** (records with `startTrigger="day"` are skipped). On an open record sets `startedAt`; on a closed record sets `endedAt`. `<time>` is `HH:MM` (1–2 digit hour, 2 digit minute — keeps the date of the existing timestamp) or any string `DateTimeImmutable` parses (e.g. `2026-05-09 10:00`). Prints an Old/New diff and waits for `[y/N]` confirmation; saves on `y`, exits without writing on anything else. Errors when there is no eligible record, when the result would have non-positive duration, or when the time can't be parsed.
- `php timeshit.php before <offset>` — same target/confirmation flow as `at`, but shifts the relevant timestamp **earlier** by `<offset>` (open record → `startedAt`; closed → `endedAt`).
- `php timeshit.php after <offset>` — shifts the `endedAt` of the last non-day record **later** by `<offset>`. Errors when the last non-day record is open (use `before` / `at` to move its start, or close it first).
- `php timeshit.php configure` (hidden — not listed in `--help`) — interactive prompts for `youtrackBaseUrl`, `timezone`, and `youtrackToken`; writes `config/config.neon` and `config/secrets.neon` via `Neon::encode`. Implemented in `Timeshit\Configurator` (so the bootstrap can run it without first constructing an `App`). Existing values appear as `[default]` hints (just press Enter to keep them); for the token the hint is `[press Enter to keep existing]`. Auto-triggered by `timeshit.php` when `config/secrets.neon` is missing (so the first run on a fresh checkout is self-bootstrapping); can also be invoked explicitly to re-configure later.

`<offset>` (used by `before` / `after`) is a YouTrack-style duration: components `d` / `h` / `m` in that order, any subset, whitespace and case-insensitive (`30m`, `1h`, `1h 20m`, `1h30m`, `1d 4h 15m`, `1H 20M` all work). Out-of-order components (e.g. `20m 1h`) and zero totals (`0m`) are rejected. Resolved through `Resolver::parseOffset` (returns total minutes).

When `at` / `before` shifts the **open** record's `startedAt` and the immediately-prior record's `endedAt` matched the **old** `startedAt` (and that prior record is not a day record), the prior record's `endedAt` is shifted to match the new value too — so adjacent segments stay adjacent. Both records appear in the Old/New diff. The first time a record's `startedAt` or `endedAt` is rewritten, the previous value is captured into `origStartedAt` / `origEndedAt`; subsequent rewrites do **not** overwrite the original (so the very first canonical timestamp is always recoverable).

Future commands (start, end, etc.) follow the same `timeshit.php <command>` pattern.

CLI entry-point scripts live at the project root (no `bin/` directory). PHP 8.2+ (per composer.json). `timeshit.php` loads `vendor/autoload.php` and dispatches to `App` — composer's PSR-4 (`Timeshit\` → `src/`) handles all class loading, plus the runtime dep on `nette/neon`.

## Static analysis

PHPStan level 8 with `phpstan-strict-rules` must pass. `phpstan.neon` covers `src/`, `tests/`, and `timeshit.php`, with `phpt` added to `fileExtensions` so test files are scanned. `src/Console.php` and `src/Os.php` (FFI / Windows console-mode bindings) are listed under `excludePaths` for now — they can't pass at level 8 without dedicated FFI stubs; revisit when those are written. Run:

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
- `tests/TypeMatching.phpt` exercises `Resolver::matchType()` (used by every command that takes a `<type>` argument) — case-insensitive exact match, unique-prefix resolution, the caller-supplied `$allowedNames` whitelist filtering (disallowed types like `Investigation`/`Internal Meetings` are invisible even on exact match), unknown-type errors that list only the allowed types, and the `<cmd>:` prefix in error messages.
- `tests/OffsetParsing.phpt` exercises `Resolver::parseOffset()` (the `<offset>` argument for `before` / `after`) — single units, combined `d`/`h`/`m` with and without spaces, case-insensitivity, empty/missing input, zero-total rejection, garbage rejection, and the requirement that components appear in `d`-`h`-`m` order (`20m 1h` is rejected). Error messages preserve the `<cmd>:` prefix.
- `tests/TimeResolution.phpt` exercises `Resolver::resolveTime()` (the `<time>` argument for `at`) — bare `HH:MM` keeping the date of the existing timestamp (and zeroing sub-minute precision), 1-digit hour acceptance, out-of-range rejection (`24:00` / `12:60`), full-datetime fallthrough via `DateTimeImmutable`, empty/missing input, and garbage rejection. Error messages preserve the `<cmd>:` prefix.
- `tests/bootstrap.php` — shared harness loaded by the `CmdLocal*.phpt` tests below. Wires an `App` with the in-memory infrastructure (`Local\InMemoryRecordStore`, `Util\FixedClock`, `Util\BufferedIo`, `Youtrack\StubTypeProvider`, `Youtrack\StubIssueDataProvider`). Defines a global `newApp(string $now, list<Record> $records, list<string> $typeNames): array{App, InMemoryRecordStore, FixedClock, BufferedIo}` so each test can spin up an isolated `App` in a few lines and then advance the clock / queue inputs / inspect the store between commands.
- `tests/CmdLocalLifecycle.phpt` — single-command coverage for `track` / `end` / `pause` / `resume`: default vs explicit type, issue-id uppercasing, no-op on same-issue+type, switch-to-different-issue, validation errors (invalid id, missing args, unknown type), end-with-comment merge, pause/resume cycle, comment placement on resumed segment, and "no record to resume" / "already open" guards.
- `tests/CmdLocalAnnotate.phpt` — `type` / `switch` / `comment`: in-place type change preserves `startedAt`, no-op on same canonical name, `switch` clones the open record and marks both segments with `switched`, `comment` targets open record (`active`) vs latest closed (`last closed`), day records are skipped, comments merge with `' | '`, missing-text rejection.
- `tests/CmdLocalEdit.phpt` — `at` / `before` / `after` / `skip` / `steal`: `at HH:MM` on open shifts `startedAt`, on closed shifts `endedAt`, cancel-on-`n` leaves the store untouched, no-op on same value, non-positive-duration guard; `before` captures `origStartedAt` once and only once, adjacency drags the prior record's `endedAt`, malformed offset rejection; `after` open-record rejection; `skip` two-record split + offset-too-large guard; `steal` three-record write with default + explicit type, invalid issue rejection, no-open / offset-too-large guards.
- `tests/CmdLocalSnapshot.phpt` — `checkout` / `day` / `status`: branch-derived issue id (incl. fallback for non-issue branches), branch+repo persistence, no-op on same checkout, switch-branch closes prior; `day` writes a closed 09:00–17:00 record with `startTrigger='day'`, refuses duplicates on same calendar date, inserts before any open record so the open-is-latest invariant holds; `status` covers no-records / open-only / closed-only / both, and the day-record skip.
- `tests/CmdLocalCombo.phpt` — multi-command flows: `track`→`pause`→`resume`→`end`; `track`→`switch`→`end` (multi-type segments); `track`→`end`→`track`→`before` triggering adjacency (prior `endedAt` and new `startedAt` shift together with `origEndedAt`/`origStartedAt` capture); `track`→`comment`→`switch` (comment stays on the first segment); `track`→`steal`→`end` (interruption flow yielding three records); `end`→`comment` lands on the freshly-closed record; `at` after `end` followed by `track` proves `origEndedAt` persists across subsequent writes; `track`→`before`→`end`→`at` accumulates both `origStartedAt` and `origEndedAt` on a single record.

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

## Wiring & test infrastructure

`App` takes its collaborators by constructor injection so tests can swap them for in-memory fakes without touching the filesystem or hitting YouTrack:

- `Config` — value object with `youtrackBaseUrl`, `youtrackToken`, `timezone`, `allowedTypes`. Construct directly in tests (`new Config(...)`); production loads it via `Config::load($rootDir)`.
- `Local\RecordStore` — append-only record store. Production: `Local\FileRecordStore` (writes `data/records.neon`). Tests: `Local\InMemoryRecordStore`.
- `Youtrack\TypeProvider` — work-item type list (with cache + auto-fetch). Production: `Youtrack\CachedTypeProvider`. Tests: `Youtrack\StubTypeProvider`.
- `Youtrack\IssueDataProvider` — issue + work-item data and `issueId => title` lookups. Production: `Youtrack\CachedIssueDataProvider`. Tests: `Youtrack\StubIssueDataProvider`.
- `Util\Clock` — `now(): DateTimeImmutable` + `nowMinute(): string` (`Y-m-d H:i`). Production: `Util\SystemClock`. Tests: `Util\FixedClock` (mutable cursor with `set()` / `advance()`).
- `Util\Io` — `out(string)` / `err(string)` / `readLine(): ?string`. Production: `Util\StdIo` (STDOUT/STDERR/STDIN). Tests: `Util\BufferedIo` (captures buffers; `setInputs([...])` queues `readLine()` answers — feed `'y'` to auto-confirm `before` / `after` / `at`).
- `Configurator` — the interactive first-run flow; takes `$rootDir` + `Util\Io`.

`App::forRoot(string $rootDir, Config $config, Io $io): self` is the production factory — it wires the file/network-backed impls. The bootstrap in `timeshit.php` is the only caller. Tests construct `App` directly with the in-memory variants (see `tests/AppScenario.phpt` for the full pattern).

`RecordStore` mutators that bump `modifiedAt` (`changeOpenType`, `commentLast`) take it as a parameter; `App` passes `$this->clock->nowMinute()`. The store has no hidden `date()` calls. The same applies to `endOpen`, which has always taken `endedAt` explicitly.

`Resolver::resolveType` takes a `Closure(): list<WorkItemType>` so the cache is only consulted when an actual lookup is needed; `App` passes `$this->types->types(...)` (first-class callable) so a `StubTypeProvider` works without having to load anything.

## Local tracking

`data/records.neon` is a NEON map with two top-level keys: `lastId` (the highest id ever generated — persisted separately so the counter survives archival of high-id records, where `max(items.id)` would no longer reflect it) and `items` (the append-only list of tracking records). Each record carries an auto-increment integer `id` minted via `RecordStore::nextId()`, plus `issueId`, `repo`, `type`, `startedAt` / `startTrigger`, (once closed) `endedAt` / `endTrigger`, and the bookkeeping fields `createdAt` (set when the record is first written and never changes) and `modifiedAt` (bumped on every mutation: `withEnd` / `withType` / `withComment` / `withStartedAt` / `withEndedAt`). Pre-existing files without `lastId` / per-item `id` are migrated on first load: ids are assigned in array order starting from 1, `lastId` is set to the highest assigned, and the file is rewritten once. Records that were rewritten by `at` / `before` / `after` also carry `origStartedAt` and/or `origEndedAt` — the very first value of the corresponding field, captured on the first rewrite and never overwritten afterwards (so subsequent edits stack onto the same `orig…` baseline). Both fields are omitted from the NEON when null, like `branch`. For most records `createdAt === startedAt`; the exception is the `day` command, where `startedAt` is 09:00 of the chosen day while `createdAt` is when the user actually ran the command. Records originating from `checkout` also carry `branch` (the git branch name); records from `track` (manual) and `day` omit `branch` from the NEON entirely. Timestamps are stored in the timezone configured in `config/config.neon` (`timezone: …`) and formatted as `Y-m-d H:i` — no offset suffix, space (not `T`) between date and time, no seconds (e.g. `2026-05-09 09:59`). `App::run()` calls `date_default_timezone_set($this->config->timezone)` once per command (after the help-only early return) so every subsequent `date(…)` / `new DateTimeImmutable(…)` lives in that timezone — including the `setTime(9, 0)` / `setTime(17, 0)` calls in `day`. Inside `App`, "now" never goes through `date()` directly — it comes through the injected `Util\Clock` (use `$this->clock->nowMinute()` for the canonical `Y-m-d H:i` form, or `$this->clock->now()` for arithmetic). Tests can drive the timeline deterministically with `Util\FixedClock`. Pre-existing records that lack `createdAt` / `modifiedAt` are loaded with both falling back to `startedAt`, and the next mutation rewrites them with real values. The latest record is the only one allowed to be open (`endedAt: null`). The `track` (manual) and `checkout` (git hook) subcommands both close that open record and open a new one in a single write; concurrent shells therefore race, but in practice branch checkouts are serial. Not yet synced to YouTrack.

To install the post-checkout hook in another repo:

```
ln -s /home/vlasta/dev/php/timeshit/hooks/post-checkout <repo>/.git/hooks/post-checkout
```

Or set globally for all repos: `git config --global core.hooksPath /home/vlasta/dev/php/timeshit/hooks`. The hook expects `ts` on `PATH` (a script, not a shell alias — git hooks run non-interactively and do not source rc files).

## Status

- YouTrack: read-only — list issues the current user is assigned to / reported / commented on / last updated / has logged work on / starred / mentioned in, with per-issue role tags. Also pulls all work items the current user authored, and the global work-item-type list. 24h NEON caches at `data/issues.neon`, `data/work-items.neon`, and `data/work-item-types.neon`. Run with `php timeshit.php issues` (cached), `php timeshit.php pushed` (already-synced work items by week/day), or `php timeshit.php refresh` (force-refresh issues, work items, and types).
- Local server: not started.
- CLI: branch-switch tracking via `checkout` (called from `hooks/post-checkout`) and manual switching via `track` both write to `data/records.neon`. `local` displays the local log grouped by week/day with start/end times and triggers. After-the-fact editing via `at` / `before` / `after` is wired up (with adjacency adjustment for the previous record, and `origStartedAt` / `origEndedAt` preserving the first-recorded values). First-run bootstrapping is handled by `timeshit.php` (auto `composer install`) and the hidden `configure` command (auto-triggered when `config/secrets.neon` is missing). Sync to YouTrack not started.
- GitLab integration: not started.
- Browser plugin / favelet: not started.

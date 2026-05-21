# timeshit

A simple time tracker for personal use, integrated with YouTrack, GitLab, and Git.

## Stack

- Plain PHP 8.2+ (no framework)
- Plain HTML, CSS (no JS framework)
- PHP's built-in development server (`php -S`) for the local server
- Runtime dep: `nette/neon`. Dev deps: `phpstan`, `phpstan-strict-rules`, `nette/tester`. PSR-4 `Timeshit\` → `src/`.

## Components

- **CLI** (`timeshit.php`) — tracks task switches automatically on Git branch checkout (via `hooks/post-checkout`) and via manual `track` / `end` commands. All command logic in `Timeshit\App`.
- **Local server** — not started. Will receive events from a browser plugin / favelet (e.g. when viewing a YouTrack issue or GitLab MR) and serve a simple web UI for viewing/editing tracked time.
- **Browser plugin / favelet** — not started.

## Integrations

- **YouTrack** — issue/task identification (read-only); work-item creation via `push` (write)
- **GitLab** — MR / project context (not started)
- **Git** — branch and repo as task signal (via `hooks/post-checkout`)

## Project layout

```
timeshit.php            CLI entry point; runs `composer install` if needed, then dispatches to App
config/
  config.neon           committed — non-secret config
  secrets.neon          gitignored — `youtrackBaseUrl` and `youtrackToken`
src/                    flat under `Timeshit\` by default; sub-namespaces for orthogonal concerns
  App.php               CLI dispatcher; `App::run(array $argv): int`; production factory `App::forRoot()`
  Config.php            value object; `Config::load($root)` reads both NEON files
  Configurator.php      interactive first-run / reconfigure flow
  Format.php            shared display helpers
  Resolver.php          pure static helpers for parsing CLI input (dates, times, spans, issue ids, types, commands)
  Local/                locally-tracked time entries not yet synced to YouTrack
    Record.php          immutable value object for a single tracked record
    RecordStore.php     append-only record store interface
    FileRecordStore.php production impl — NEON store at `data/records.neon`
    InMemoryRecordStore.php test impl
  Util/                 reusable utilities + injectable abstractions for time and IO
    Ansi.php            ANSI 16-color helpers + width measurement
    Clock.php, SystemClock.php, FixedClock.php   `now()` abstraction
    Io.php, StdIo.php, BufferedIo.php            stdio abstraction
  Youtrack/             REST client, DTOs, on-disk caches, provider interfaces
    YoutrackClient.php  cURL-based REST client; 1s timeouts so offline calls fail fast
    Issue.php, WorkItem.php, WorkItemType.php   value objects
    IssueCache.php, WorkItemCache.php, WorkItemTypeCache.php   on-disk NEON caches, mtime-based TTL
    TypeProvider.php, CachedTypeProvider.php, StubTypeProvider.php
    IssueDataProvider.php, CachedIssueDataProvider.php, StubIssueDataProvider.php
    WorkItemPusher.php, HttpWorkItemPusher.php, StubWorkItemPusher.php   `push` → `POST /api/issues/{id}/timeTracking/workItems`
  View/                 terminal renderers
    IssuesView.php, RecordsView.php, AllView.php
    Workdays.php        `Workdays::expand()` — Mon–Fri fill for the day rollup views
hooks/
  post-checkout         calls `ts checkout <branch> <repo>` on every branch checkout
data/                   gitignored — runtime cache
  issues.neon, work-items.neon, work-item-types.neon   24h NEON caches
  records.neon          locally-tracked time records (live)
  archive.neon          records that were pushed to YouTrack (append-only, carries `workItemId`)
phpstan.neon            level 8 + strict rules; covers src/, tests/, timeshit.php
```

For per-class details, read the file. Conventions and non-obvious wiring are documented below.

## CLI

Entry script `timeshit.php` is a thin bootstrap: requires `vendor/autoload.php`, handles `--help` / no-args / first-run-`configure` directly, otherwise builds `App::forRoot($rootDir, $config, new StdIo())` and calls `->run($argv)`. All command logic lives in `Timeshit\App`.

Subcommands (read `App.php` for argument shapes, error cases, and log-entry wording):

- **Read-only:** `status`, `issues`, `time`, `archive`, `types`, `refresh`
- **Sync:** `push [<date>]` — sum closed records by (day, issue, type) up to `<date>` (default: `yesterday`, accepts `today` to include today) and POST a work item per group; on success the records move to `data/archive.neon` with the assigned `workItemId` and `status='synced'`; on failure they stay in `data/records.neon` with `status='failed'` and a `sync failed (…)` log entry. Open records and already-`synced` records are skipped. `untracked` break records are not pushed themselves, but once any group on the same day pushes successfully they're moved to `data/archive.neon` too (status preserved, log gets `archived at <time> (push)`) so completed days drop out of `data/records.neon` entirely. `failed` records are retried on the next `push`.
- **Track:** `track <issue> [<type>]`, `checkout <branch> <repo>` (git hook), `interrupt <issue> [<type>]`, plus any `customCommands` from `config.neon` (see below)
- **Annotate / mutate the current record:** `type [#<id>] <type>`, `switch <type>`, `note [#<id>] <text>`, `fix [#<id>] <issue>` (rewrites the issue id; refuses `untracked` break records). `type` / `note` / `fix` default to the latest non-`day` record (open or closed), so they also work after `end`; `switch` requires an open record.
- **Pause / resume / close:** `pause [<note>]`, `resume [<note>]`, `end [<note>]`, `done [<note>]`
- **Backfill:** `days [<days>] [<issue>] [<type>] [<note>]` (zero/one/two leading date args; two = weekday range — the dispatcher peels leading args that parse as dates), `skip <span>`, `grab <issue> <span> [<type>]`, `put <issue> <span> [<type>]`
- **Edit timestamps (with [y/N] confirm):** `at [#<id>] <time>`, `before [#<id>] <span>`, `after [#<id>] <span>`, `fit` (snap the open record's start to the previous record's end)
- **Free-form edit:** `edit <id>` — opens the record (by id) in `$config->editor` as NEON, validates and re-saves
- **Hidden:** `configure` (also auto-triggered when `config/secrets.neon` is missing)

All CLI error output is red on STDERR via `Ansi::red`; exit code is `1`.

### Command name resolution

The dispatcher resolves the subcommand the same way `<type>` and `<date>` keywords are resolved: case-insensitive exact match wins, otherwise unique case-insensitive prefix. Exact matches always win even when the name is also a prefix of another command (e.g. `ts at` runs `at`). Ambiguous prefixes error in red and print help; unknown input falls through to "Unknown command" + help. `help`, `-h`, `--help` keep their special early-return behavior. The full pool is `Help::BUILTIN_COMMAND_NAMES` + `customCommands` names + `commandAliases` keys; after matching, an alias is translated to its canonical command before dispatch. Aliases participate in prefix uniqueness — adding alias `design` for `analyse` makes the prefix `de` ambiguous with `delete`.

**Issue-id shortcut:** when no command matches but the first arg looks like an issue id (pure digits like `42` or standard `ABC-123` form, via `Resolver::looksLikeIssueId`), the dispatcher splices `track` into `argv` and dispatches as `ts track <issue> [<type>] [<note>…]`. The log entry reads `(track)` — the shortcut is indistinguishable from explicit `track` once recorded. Inputs that don't match the issue-id shape still error as "Unknown command", so typos like `ts trakc` aren't silently accepted as an issue.

### Command aliases

`config.neon` may declare a `commandAliases:` block — a flat map of `alias => canonical`. Each canonical must be a real builtin or custom command (no alias chains). Aliases cannot collide with builtin or custom names. After resolution, the alias is replaced by its canonical, so `ts design SW-1` dispatches as `ts analyse SW-1` and writes `(analyse)` to the log. In help, aliases render inline as `(alias: x, y)` next to the canonical's row — they don't get their own row.

### Trailing `<note>` on action commands

`track`, `interrupt`, `put`, `grab`, `days`, and every `customCommand` accept an optional trailing free-form `<note>`. The note occupies every argv slot AFTER the parent's fixed positional slots:

- `track`/`interrupt`: positionals are argv[2]=issue, argv[3]=type; note is argv[4..N] joined with spaces.
- `put`/`grab`: positionals are argv[2..4]; note is argv[5..N].
- `days`: variable-length. The dispatcher peels up to two leading argv slots as dates via `Resolver::looksLikeDate`, then reads `<issue>`, `<type>`, and `<note…>` from the remaining slots. `days SW-1234` skips date peeling because `SW-1234` doesn't look like a date; `days 7 SW-1234` treats `7` as day-of-month and `SW-1234` as the issue. Custom command pre-fills (`issue:`/`type:`/`day:`) consume their slot — when set, the user cannot pass that arg on the CLI.
- Custom commands (non-`days` parent): the slot starts after however many CLI positionals the spec did not pre-fill (`issue`, `span`).

The user is expected to wrap multi-word notes in shell quotes (`ts track SW-1 Impl "fix the leak"`), but `Resolver::joinFromIndex` simply joins everything after the positional slot — so unquoted multi-word notes also work for the commands where there's no positional left to collide with (e.g. `ts meeting standup with team`).

In `printHelp`, the note slot renders as `…` in light blue (`Ansi::lblue('…')`) so the signatures stay narrow. The slot is added to every action command except `days` (and to custom commands whose parent is not `days`).

### Custom commands

`config.neon` may declare a `customCommands` block — a map of `name => spec` where each spec has:

- `parent` — any action command: `track`, `interrupt`, `put`, `grab`, `days`, `pause`, `resume` / `continue`, `done`, `end`, `switch`, `skip`. Controls the underlying behavior.
- `type` — work-item type. Required (must be in `allowedTypes`) for parents that record a type (`track`, `interrupt`, `put`, `grab`, `days`, `switch`). Optional/ignored for parents that don't (`pause`, `done`, `end`, `resume`, `skip`).
- `issue` — optional pre-set issue id. When set, the user does not pass `<issue>` on the CLI. Applies to record-creating parents.
- `note` — optional default note. Always applied; when the CLI also supplies a trailing note, the two are joined with ` | ` (default first, CLI detail trailing) via `App::joinNote`. Applies to parents that take notes (`track`, `interrupt`, `put`, `grab`, `days`, `pause`, `done`, `end`).
- `span` — optional default duration. Applies to `put`, `grab`, `skip`.
- `day` — optional default date. Applies to `days`, pre-filling the single-day case (user passes 0 CLI date args).

`App::cmdCustom(CustomCommand, $argv, $noteArg)` dispatches by parent via a switch: track/interrupt route to `startRecord`; `put`/`grab` call the matching `cmdPut`/`cmdGrab` with pre-filled args; `days` routes through `cmdDaysCli` (see `App::cmdDaysCli`), which handles its own date peeling and pre-fill consumption; `pause`/`done`/`end`/`resume`/`switch`/`skip` call their builtin counterpart with the appropriate defaults. Fields that don't apply to the parent are ignored.

`App::noteStartIndex()` computes the argv slot where the trailing note begins. For customs it accounts for which positionals are pre-filled in the spec; for builtin commands it follows the parent's structure (track/interrupt=4, put/grab=5). `days` returns -1 because its date peeling is variable-length — `cmdDaysCli` extracts the note from the leftover argv slots after peeling instead.

The stock commands (`analyse`, `design`, `implement`, `review`, `test`, `meeting`, `mail`, `vacation`) live in `config/config.neon` as `customCommands`, not as hardcoded methods — add or remove freely.

### Type matching

Every command that takes a `<type>` argument (`track`, `type`, `switch`, `grab`, `interrupt`, `days`) resolves it through `Resolver::matchType`. The set of valid canonical names is constrained by `Config::$allowedTypes` (whitelist in `config.neon`); aliases (e.g. `Design` → `Analyses / Design`) come from `Config::$typeAliases`. Resolution: case-insensitive exact match on any candidate (canonical or alias) wins, then unique-canonical prefix match across candidates, then ambiguous-error, then unknown-error. Canonical casing from the cache is what gets written to the record.

`Resolver::resolveType($cmd, $input, $default, $typesLoader, $allowedNames, $aliases)` wraps the default/missing handling around `matchType` and takes a `Closure(): list<WorkItemType>` for the cache so the lookup only fires when needed (i.e. *not* when the default applies). `App` passes `$this->types->types(...)` (first-class callable).

### Span grammar

`<span>` (used by `before` / `after` / `skip` / `grab`) is YouTrack-style: components `d` / `h` / `m` **in that order**, any subset, whitespace and case-insensitive (`30m`, `1h 20m`, `1h30m`, `1d 4h 15m`). Out-of-order (`20m 1h`) and zero totals (`0m`) are rejected. Parsed by `Resolver::parseSpan` (returns total minutes).

### Adjacency adjustment

When `at` / `before` shifts the open record's `startedAt` and the immediately-prior record's `endedAt` matched the **old** `startedAt` (and the prior record is not a `day` record), the prior's `endedAt` shifts to match the new value too — so adjacent segments stay adjacent. Both records appear in the Old/New diff. Each shift appends an `edited <field> from <old> to <new> at <now> (<cmd>)` entry to the affected record's `log`.

### Edit-by-id targeting

`at`, `before`, `after`, `type`, `note`, `fix` accept an optional leading `#<id>` token that picks the record to edit instead of using the default "last non-day record" / "open record". `Resolver::peelRecordId` parses the token; the rest of the argv (joined by spaces, like `note`) is the command's normal argument. `day`-status records are refused. The default-when-missing behavior is unchanged: `type` / `note` go through `RecordStore::changeLastType` / `noteLast`; `at` / `before` / `after` scan backwards for the last non-day record. `changeLastType` also skips `untracked` break records so `type` during a pause targets the real record behind the break; `noteLast` does not (a note on the break is meaningful).

## Local tracking

`data/records.neon` is a NEON map with two top-level keys:

- **`lastId`** — the highest id ever generated. Persisted separately so the counter survives archival of high-id records, where `max(items.id)` would no longer reflect it. **Never reset this value, even when `items` is empty** — read it, preserve it, write it back unchanged.
- **`items`** — the append-only list of tracking records.

Each `Record` has nine fields: `id` (auto-increment via `RecordStore::nextId()`), `issueId`, `type`, `startedAt`, `endedAt` (nullable for open records), `note`, `status`, `log` (a `' | '`-joined audit trail), and `workItemId` (empty until the record is pushed to YouTrack). Legacy records with a `comment` key are read into `note` automatically. Legacy records without `status` decode as `'new'`. Pre-existing files without `lastId` / per-item `id` are migrated on first load. `workItemId` is omitted from the on-disk shape when empty.

The **latest record is the only one allowed to be open** (`endedAt: null`). `track` and `checkout` both close any open record and append a new one in a single write.

Timestamps are stored in the timezone configured in `config.neon` (`timezone: …`) and formatted as `Y-m-d H:i` (no offset, space between date and time, no seconds). `App::run()` calls `date_default_timezone_set($this->config->timezone)` once per command so every subsequent `date()` / `DateTimeImmutable` lives in that timezone — including `setTime(9, 0)` / `setTime(17, 0)` in `days`. Inside `App`, "now" goes through `Util\Clock` (`$this->clock->nowMinute()` for the canonical form, `->now()` for arithmetic), never through `date()` directly.

### Status field

The `status` field is an enum-style string:

- `'new'` — default; fresh record, open or closed
- `'paused'` — closed record paused by `cmdPause` or by the interruption flow; what `done` / `resume` look at to find work to bring back
- `'untracked'` — the *break* record produced by `pause` (no issue, no type, covers the time you're away)
- `'day'` — full-day record produced by `days` (or any custom command on top of `days`, e.g. `vacation`); skipped by `status`, `note`, `at` / `before` / `after`
- `'synced'` — record pushed to YouTrack — set by `push` on the moved-to-archive record (also carries `workItemId`)
- `'failed'` — `push` attempt failed; record stays in `data/records.neon` with a `sync failed (<reason>) at <time> (push)` log entry and is retried on the next `push`

The pause-state flip is driven by an explicit `$pauseClosed: bool` parameter on `RecordStore::track` / `RecordStore::endOpen`. `App` sets it in `cmdPause` and in the interruption-detection branch of `startRecord`.

### Interruption flow

`startRecord` (called by `track`, `interrupt`, `checkout`) detects the interruption case via two channels:

1. **Type-based:** the latest record is open AND its `type === Config::$defaultTrackType` AND the new record's type is in `Config::$interruptionTypes`.
2. **Explicit:** the caller passes `forceInterruptIfOpen: true` (only `cmdInterrupt` does).

Either path sets `$pauseClosed = true` on the call into `RecordStore::track`; the trigger string itself stays as the caller's command name (`track` / `interrupt` / `checkout`). The store closes the open record with a `closed at <now> (<trigger>)` log entry AND flips its `status` to `'paused'`. `cmdSwitch`, `cmdResume`, `cmdGrab` call `RecordStore::track` directly with `$pauseClosed = false`. When `interrupt` runs with no open record, neither path fires; the new record is just started with `status='new'`.

`done` is a two-step write: `endOpen($now, 'done', $note)` closes the open record, then a backward scan from `count-2` looks for the most recent `status === 'paused'` record. When found, a clone is appended (same `issueId`/`type`, log tagged `(done)`); when none, `done` is identical to `end`. Because both manual `pause` and auto-pause-on-interruption produce `status='paused'`, **`done` resumes either kind**. The scan skips `'untracked'` break records.

`resume` follows the same status-based scan: walks backwards for `status === 'paused'`, falling back to the most recent closed non-`untracked` record only when nothing is paused. So after `track A → track B (interrupts A) → end B`, `resume` brings A back, not B.

### Installing the post-checkout hook

```
ln -s /home/vlasta/dev/php/timeshit/hooks/post-checkout <repo>/.git/hooks/post-checkout
```

Or globally: `git config --global core.hooksPath /home/vlasta/dev/php/timeshit/hooks`. The hook expects `ts` on `PATH` (a script, not a shell alias — git hooks run non-interactively and do not source rc files).

## Wiring & test infrastructure

`App` takes its collaborators by constructor injection so tests can swap them for in-memory fakes without touching the filesystem or hitting YouTrack:

- `Config` — construct directly in tests (`new Config(...)`); production loads via `Config::load($rootDir)`.
- `Local\RecordStore` — production: `FileRecordStore`. Tests: `InMemoryRecordStore`.
- `Youtrack\TypeProvider` — production: `CachedTypeProvider`. Tests: `StubTypeProvider`.
- `Youtrack\IssueDataProvider` — production: `CachedIssueDataProvider`. Tests: `StubIssueDataProvider`.
- `Youtrack\WorkItemPusher` — production: `HttpWorkItemPusher` (wraps `YoutrackClient::createWorkItem`). Tests: `StubWorkItemPusher` (queue results via `setResults([...])`; record-of-calls in `$pusher->calls`).
- `Util\Clock` — production: `SystemClock`. Tests: `FixedClock` (mutable cursor with `set()` / `advance()`).
- `Util\Io` — production: `StdIo`. Tests: `BufferedIo` (captures `out` / `err`; `setInputs([...])` queues `readLine()` answers, e.g. `'y'` to auto-confirm `at` / `before` / `after`).
- `Configurator` — interactive first-run flow.

`App::forRoot(string $rootDir, Config $config, Io $io): self` is the production factory; the bootstrap in `timeshit.php` is the only caller. Tests construct `App` directly via the shared `tests/bootstrap.php` helper.

`RecordStore` mutators that append to the `log` (`changeOpenType`, `noteLast`, `track`, `endOpen`, `archive`, `markFailed`) take both the timestamp and the trigger (command name) as parameters — `App` passes `$this->clock->nowMinute()` and the matching command name. The store has no hidden `date()` calls. `track` and `endOpen` also take `bool $pauseClosed = false`.

`archive(list<int> $ids, $workItemId, $time, $trigger)` removes the named records from `data/records.neon`, applies `Record::markSynced(...)` (sets `status='synced'`, fills `workItemId`, appends `synced as <id> at <time> (push)` to the log), and writes them to `data/archive.neon`. `markFailed(list<int> $ids, $reason, $time, $trigger)` updates the named records in place with `Record::markFailed(...)` (`status='failed'` + `sync failed (<reason>) at <time> (push)` log entry). `archiveUntracked(list<int> $ids, $time, $trigger)` moves the named records to `data/archive.neon` with a `archived at <time> (<trigger>)` log entry and the existing status preserved (no `markSynced` — there's no work item); `cmdPush` calls it for `untracked` break records on every day where at least one group pushed successfully. All three run inside the same lock as `records.neon`. `FileRecordStore::__construct($recordsPath, $archivePath)` takes both paths.

## Conventions

- Secrets in `config/secrets.neon` (gitignored — `youtrackBaseUrl`, `youtrackToken`). Non-secret config in `config/config.neon` (committed). `Config::load($root)` reads both; `Config::timezone($root)` reads only the public file. The `configure` command (auto-triggered when `secrets.neon` is missing) only writes `secrets.neon` — it never touches `config.neon`.
- HTTP via cURL directly; no Guzzle. Same interface can later be swapped if needed. `CURLOPT_CONNECTTIMEOUT` is 1s; `CURLOPT_TIMEOUT` is 1s for `GET` (offline must fail fast) but 10s for the `push` `POST` (write may take longer server-side).
- **Offline policy:** every command except `refresh` and `push` must keep working without a network. The cached providers (`CachedTypeProvider`, `CachedIssueDataProvider`) catch the `RuntimeException` from `YoutrackClient`, print a yellow `Offline (…); using cached …` warning on stderr, and fall back to whatever stale cache exists (or empty data). `refresh()` / `refresh` subcommand and `push` intentionally propagate the network error — `push` translates each per-group failure into `status='failed'` on the affected records rather than aborting the whole batch.
- "Current user" in YouTrack queries is implicit via the token — we use the `me` keyword (e.g. `assignee: me`).
- YouTrack custom field shapes vary (User / Enum / Period / …). The client returns raw decoded issues; callers extract via `Client::customFieldValue($issue, $name)` and interpret the shape themselves. Field names assumed: `State`, `Type`, `Assignee`, `Category`, `Spent time`.
- To know *why* an issue was downloaded, the client runs one query per role (`assignee: me`, `commenter: me`, `reporter: me`, `updater: me`, `tag: Star`, `mentions: me`) plus `/api/workItems?author=me`, and merges by issue ID. Each `Issue` carries a `roles: list<string>`.
- **Closed-issue retention:** `config.neon.closedIssueRetentionDays` (default `90`) drops closed issues from the bulk-pulled cache once their `resolved` date is older than the threshold. Applied inside `CachedIssueDataProvider::fetchAndCache` only to fresh `fetchMine` results — extras carried via `extraIds` (issues you explicitly tracked) bypass the filter so they stay regardless of age. Set to `0` to disable. Since refresh overwrites the file, this also acts as the cleanup mechanism. `Issue::$resolved` is a `Y-m-d H:i` string so the comparison is a plain lexical `<` against the cutoff string.

## Static analysis

PHPStan level 8 with `phpstan-strict-rules` must pass. `phpstan.neon` covers `src/`, `tests/`, and `timeshit.php`, with `phpt` added to `fileExtensions`. `src/Console.php` and `src/Os.php` (FFI / Windows console-mode bindings) are excluded until targeted stubs are written.

```
vendor/bin/phpstan analyse
```

Type-narrowing rules learned the hard way:

- API responses (decoded JSON) are typed as `array<int|string, mixed>` at the boundary. Narrowing to a specific shape happens once, in the client, by parsing into a value object. Do not chain `is_array` / `is_string` checks at every call site — push the narrowing into a parser method.
- For `cURL` calls, `curl_init()` can return `false` (must be checked); `curl_getinfo($ch, CURLINFO_HTTP_CODE)` is already typed `int` in stubs (do not cast or `is_int`-check).
- Don't use `@phpstan-ignore`, baseline entries, inline `@var` to override inferred types, `assert()`, or pointless casts to silence errors. Fix the underlying type.

## Tests

Unit tests under `tests/` run on [nette/tester](https://github.com/nette/tester). Test files use the `.phpt` extension and load `vendor/autoload.php`.

```
vendor/bin/tester tests
```

Test files (read the file for the scenario list):

- `tests/DaySelection.phpt` — `Resolver::resolveDate()`
- `tests/TypeMatching.phpt` — `Resolver::matchType()` incl. the alias pathway
- `tests/SpanParsing.phpt` — `Resolver::parseSpan()`
- `tests/TimeResolution.phpt` — `Resolver::resolveTime()`
- `tests/Workdays.phpt` — `View\Workdays::expand()`
- `tests/bootstrap.php` — shared harness that wires an `App` with in-memory infrastructure; exposes `newApp(string $now, list<Record> $records, list<string> $typeNames)`
- `tests/CmdLocalLifecycle.phpt` — `track` / `end` / `pause` / `resume` single-command coverage
- `tests/CmdLocalAnnotate.phpt` — `type` / `switch` / `note`
- `tests/CmdLocalEdit.phpt` — `at` / `before` / `after` / `fit` / `skip` / `grab`
- `tests/CmdLocalSnapshot.phpt` — `checkout` / `days` / `status`
- `tests/CmdLocalCombo.phpt` — multi-command flows
- `tests/CmdLocalInterruption.phpt` — auto-pause/auto-resume flow, explicit `interrupt`, status-field lifecycle
- `tests/CmdPush.phpt` — `push` grouping by (day, issue, type), cutoff handling (`yesterday` default, `today` opt-in, explicit date), archive vs. failed-stay-in-records, retry of `failed`, skipping of open / `untracked` / `synced` records

## Status

- **YouTrack:** read-only listing — `issues`, `refresh` with 24h NEON caches at `data/issues.neon`, `data/work-items.neon`, `data/work-item-types.neon`. Write: `push` creates work items via `POST /api/issues/{id}/timeTracking/workItems`; archived synced records live in `data/archive.neon`.
- **CLI:** branch-switch tracking via `checkout` and manual switching via `track` both write to `data/records.neon`. `time` (YouTrack + local rollup) / `archive` (pushed records) views available. After-the-fact editing via `at` / `before` / `after` / `edit` is wired up. `push` syncs closed records to YouTrack.
- **Local server:** not started.
- **GitLab integration:** not started.
- **Browser plugin / favelet:** not started.
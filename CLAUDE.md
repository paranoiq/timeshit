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
  secrets.neon          gitignored — `youtrackToken` only
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
    IssuesView.php, WorkView.php, RecordsView.php, AllView.php
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

- **Read-only:** `status`, `issues`, `remote`, `local`, `all`, `types`, `refresh`
- **Sync:** `push [<date>]` — sum closed records by (day, issue, type) up to `<date>` (default: `yesterday`, accepts `today` to include today) and POST a work item per group; on success the records move to `data/archive.neon` with the assigned `workItemId` and `status='synced'`; on failure they stay in `data/records.neon` with `status='failed'` and a `sync failed (…)` log entry. Open records and `untracked` / already-`synced` records are skipped. `failed` records are retried on the next `push`.
- **Track:** `track <issue> [<type>]`, `checkout <branch> <repo>` (git hook), `interrupt <issue> [<type>]`
- **Annotate / mutate the open record:** `type [#<id>] <type>`, `switch <type>`, `note [#<id>] <text>`
- **Pause / resume / close:** `pause [<note>]`, `resume [<note>]`, `end [<note>]`, `done [<note>]`
- **Backfill:** `day <issue> [<date>] [<type>]`, `skip <span>`, `grab <issue> <span> [<type>]`, `put <issue> <span> [<type>]`
- **Edit timestamps (with [y/N] confirm):** `at [#<id>] <time>`, `before [#<id>] <span>`, `after [#<id>] <span>`
- **Free-form edit:** `edit <id>` — opens the record (by id) in `$config->editor` as NEON, validates and re-saves
- **Hidden:** `configure` (also auto-triggered when `config/secrets.neon` is missing)

All CLI error output is red on STDERR via `Ansi::red`; exit code is `1`.

### Command name resolution

The dispatcher resolves the subcommand the same way `<type>` and `<date>` keywords are resolved: case-insensitive exact match wins, otherwise unique case-insensitive prefix. Exact matches always win even when the name is also a prefix of another command (e.g. `ts at` runs `at`). Ambiguous prefixes error in red and print help; unknown input falls through to "Unknown command" + help. `help`, `-h`, `--help` keep their special early-return behavior.

### Type matching

Every command that takes a `<type>` argument (`track`, `day`, `type`, `switch`, `grab`, `interrupt`) resolves it through `Resolver::matchType`. The set of valid canonical names is constrained by `Config::$allowedTypes` (whitelist in `config.neon`); aliases (e.g. `Design` → `Analyses / Design`) come from `Config::$typeAliases`. Resolution: case-insensitive exact match on any candidate (canonical or alias) wins, then unique-canonical prefix match across candidates, then ambiguous-error, then unknown-error. Canonical casing from the cache is what gets written to the record.

`Resolver::resolveType($cmd, $input, $default, $typesLoader, $allowedNames, $aliases)` wraps the default/missing handling around `matchType` and takes a `Closure(): list<WorkItemType>` for the cache so the lookup only fires when needed (i.e. *not* when the default applies). `App` passes `$this->types->types(...)` (first-class callable).

### Span grammar

`<span>` (used by `before` / `after` / `skip` / `grab`) is YouTrack-style: components `d` / `h` / `m` **in that order**, any subset, whitespace and case-insensitive (`30m`, `1h 20m`, `1h30m`, `1d 4h 15m`). Out-of-order (`20m 1h`) and zero totals (`0m`) are rejected. Parsed by `Resolver::parseSpan` (returns total minutes).

### Adjacency adjustment

When `at` / `before` shifts the open record's `startedAt` and the immediately-prior record's `endedAt` matched the **old** `startedAt` (and the prior record is not a `day` record), the prior's `endedAt` shifts to match the new value too — so adjacent segments stay adjacent. Both records appear in the Old/New diff. Each shift appends an `edited <field> from <old> to <new> at <now> (<cmd>)` entry to the affected record's `log`.

### Edit-by-id targeting

`at`, `before`, `after`, `type`, `note` accept an optional leading `#<id>` token that picks the record to edit instead of using the default "last non-day record" / "open record". `Resolver::peelRecordId` parses the token; the rest of the argv (joined by spaces, like `note`) is the command's normal argument. `day`-status records are refused. The default-when-missing behavior is unchanged: `type` / `note` go through `RecordStore::changeOpenType` / `noteLast`; `at` / `before` / `after` scan backwards for the last non-day record.

## Local tracking

`data/records.neon` is a NEON map with two top-level keys:

- **`lastId`** — the highest id ever generated. Persisted separately so the counter survives archival of high-id records, where `max(items.id)` would no longer reflect it. **Never reset this value, even when `items` is empty** — read it, preserve it, write it back unchanged.
- **`items`** — the append-only list of tracking records.

Each `Record` has nine fields: `id` (auto-increment via `RecordStore::nextId()`), `issueId`, `type`, `startedAt`, `endedAt` (nullable for open records), `note`, `status`, `log` (a `' | '`-joined audit trail), and `workItemId` (empty until the record is pushed to YouTrack). Legacy records with a `comment` key are read into `note` automatically. Legacy records without `status` decode as `'new'`. Pre-existing files without `lastId` / per-item `id` are migrated on first load. `workItemId` is omitted from the on-disk shape when empty.

The **latest record is the only one allowed to be open** (`endedAt: null`). `track` and `checkout` both close any open record and append a new one in a single write.

Timestamps are stored in the timezone configured in `config.neon` (`timezone: …`) and formatted as `Y-m-d H:i` (no offset, space between date and time, no seconds). `App::run()` calls `date_default_timezone_set($this->config->timezone)` once per command so every subsequent `date()` / `DateTimeImmutable` lives in that timezone — including `setTime(9, 0)` / `setTime(17, 0)` in `day`. Inside `App`, "now" goes through `Util\Clock` (`$this->clock->nowMinute()` for the canonical form, `->now()` for arithmetic), never through `date()` directly.

### Status field

The `status` field is an enum-style string:

- `'new'` — default; fresh record, open or closed
- `'paused'` — closed record paused by `cmdPause` or by the interruption flow; what `done` / `resume` look at to find work to bring back
- `'untracked'` — the *break* record produced by `pause` (no issue, no type, covers the time you're away)
- `'day'` — full-day OOO record produced by `day` / `vacation`; skipped by `status`, `note`, `at` / `before` / `after`
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

`archive(list<int> $ids, $workItemId, $time, $trigger)` removes the named records from `data/records.neon`, applies `Record::markSynced(...)` (sets `status='synced'`, fills `workItemId`, appends `synced as <id> at <time> (push)` to the log), and writes them to `data/archive.neon`. `markFailed(list<int> $ids, $reason, $time, $trigger)` updates the named records in place with `Record::markFailed(...)` (`status='failed'` + `sync failed (<reason>) at <time> (push)` log entry). Both run inside the same lock as `records.neon`. `FileRecordStore::__construct($recordsPath, $archivePath)` takes both paths.

## Conventions

- Secrets in `config/secrets.neon` (gitignored). Non-secret config in `config/config.neon` (committed). `Config::load($root)` reads both; `Config::timezone($root)` reads only the public file.
- HTTP via cURL directly; no Guzzle. Same interface can later be swapped if needed. `CURLOPT_CONNECTTIMEOUT` is 1s; `CURLOPT_TIMEOUT` is 1s for `GET` (offline must fail fast) but 10s for the `push` `POST` (write may take longer server-side).
- **Offline policy:** every command except `refresh` and `push` must keep working without a network. The cached providers (`CachedTypeProvider`, `CachedIssueDataProvider`) catch the `RuntimeException` from `YoutrackClient`, print a yellow `Offline (…); using cached …` warning on stderr, and fall back to whatever stale cache exists (or empty data). `refresh()` / `refresh` subcommand and `push` intentionally propagate the network error — `push` translates each per-group failure into `status='failed'` on the affected records rather than aborting the whole batch.
- "Current user" in YouTrack queries is implicit via the token — we use the `me` keyword (e.g. `assignee: me`).
- YouTrack custom field shapes vary (User / Enum / Period / …). The client returns raw decoded issues; callers extract via `Client::customFieldValue($issue, $name)` and interpret the shape themselves. Field names assumed: `State`, `Type`, `Assignee`, `Category`, `Spent time`.
- To know *why* an issue was downloaded, the client runs one query per role (`assignee: me`, `commenter: me`, `reporter: me`, `updater: me`, `tag: Star`, `mentions: me`) plus `/api/workItems?author=me`, and merges by issue ID. Each `Issue` carries a `roles: list<string>`.

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
- `tests/CmdLocalEdit.phpt` — `at` / `before` / `after` / `skip` / `grab`
- `tests/CmdLocalSnapshot.phpt` — `checkout` / `day` / `status`
- `tests/CmdLocalCombo.phpt` — multi-command flows
- `tests/CmdLocalInterruption.phpt` — auto-pause/auto-resume flow, explicit `interrupt`, status-field lifecycle
- `tests/CmdPush.phpt` — `push` grouping by (day, issue, type), cutoff handling (`yesterday` default, `today` opt-in, explicit date), archive vs. failed-stay-in-records, retry of `failed`, skipping of open / `untracked` / `synced` records

## Status

- **YouTrack:** read-only listing — `issues`, `remote`, `refresh` with 24h NEON caches at `data/issues.neon`, `data/work-items.neon`, `data/work-item-types.neon`. Write: `push` creates work items via `POST /api/issues/{id}/timeTracking/workItems`; archived synced records live in `data/archive.neon`.
- **CLI:** branch-switch tracking via `checkout` and manual switching via `track` both write to `data/records.neon`. `local` / `all` views available. After-the-fact editing via `at` / `before` / `after` / `edit` is wired up. `push` syncs closed records to YouTrack.
- **Local server:** not started.
- **GitLab integration:** not started.
- **Browser plugin / favelet:** not started.
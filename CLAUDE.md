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
timeshit.php            CLI entry point — dispatcher: `php timeshit.php <command>`
config.ini              committed — non-secret config (e.g. youtrack_base_url=...)
src/                    flat — single namespace level under `Timeshit`, no subdirs
  Ansi.php              ANSI 16-color helpers (red(), lred(), ...)
  Config.php            loads ./config.ini + secrets/youtrack-token.txt
  Format.php            shared display helpers (state/spent/roles/assignee/category)
  IssueCache.php        on-disk JSON cache for issue lists, mtime-based TTL
  IssuesView.php        renders the issues table for `timeshit.php issues`
  WorkItemCache.php     on-disk JSON cache for work items, mtime-based TTL
  WorkItemTypeCache.php on-disk JSON cache for the YouTrack work-item-type list, mtime-based TTL
  WorkLocalStore.php    append-only store for locally-tracked time entries (data/work-local.json)
  WorkView.php          renders the work-items list with day/week rollups for `timeshit.php work`
  YoutrackClient.php    cURL-based YouTrack REST client (parses JSON into value objects)
  Issue.php             immutable value object representing a single YouTrack issue
  WorkItem.php          immutable value object representing a single time-tracking entry
  WorkItemType.php      immutable value object for a YouTrack work-item type (id + name)
  WorkLocalItem.php     immutable value object for a locally-tracked entry (open or closed)
hooks/                  committed — git hook templates
  post-checkout         calls `ts track <branch> <repo>` on every branch checkout
secrets/                gitignored — tokens / credentials live here
  youtrack-token.txt    YouTrack permanent token (Bearer)
data/                   gitignored — runtime cache
  issues.json           cached issue list with per-issue role tags (refreshed every 24h)
  work-items.json       cached time-tracking entries authored by the current user
  work-item-types.json  cached global list of YouTrack work-item types (id + name)
  work-local.json       locally-tracked time entries; appended to by `track`, not yet synced
composer.json           dev deps: phpstan, phpstan-strict-rules; PSR-4 Timeshit\ → src/
phpstan.neon            level 7 + strict rules, paths: src/ + timeshit.php
```

## CLI

Single dispatcher script `timeshit.php` at the project root. Subcommands as positional argument:

- `php timeshit.php` — usage
- `php timeshit.php issues` — list YouTrack issues; uses cached `data/issues.json` if it is less than 24h old, otherwise fetches and re-caches
- `php timeshit.php work` — list your work items grouped by ISO week and day with per-day, per-week and grand totals (uses the same cache)
- `php timeshit.php refresh` — force-refresh the cache from YouTrack and list issues
- `php timeshit.php track <branch> <repo> [<type>]` — switch local time tracking to `<branch>` in `<repo>` (type defaults to `Implementation`); closes the previous open entry and opens a new one in `data/work-local.json`. No-op when the same branch/repo/type is already open. Issue id is the leading `[A-Za-z]{1,3}-\d+` (uppercased) when the branch matches, otherwise the full branch name.
- `php timeshit.php type <type>` — change the type of the currently open `work-local.json` entry in place (preserves `startedAt`). Match is case-insensitive against `data/work-item-types.json`; the canonical casing from the cache is written back. Errors when no entry is open or `<type>` is unknown. Auto-fetches the type list from YouTrack (`/api/admin/timeTrackingSettings/workItemTypes`) when the cache is missing or older than 24h.
- `php timeshit.php switch <type>` — end the currently open entry and append a new one cloned from it (same `issueId`/`branch`/`repo`) with the given `<type>`; both `endTrigger` of the closed segment and `startTrigger` of the new one are recorded as `ts switch`. Same matching/validation rules as `type`. No-op when `<type>` already matches; errors when no entry is open. Use this when you want each typed segment to be its own row (intended for eventual sync to YouTrack as separate work items).
- `php timeshit.php end [<comment>]` — close the currently open entry, recording `endTrigger=ts end`. When `<comment>` is provided it is appended to the entry's existing comment with ` | ` as separator. Errors when no entry is open.
- `php timeshit.php comment <text>` — append `<text>` to the currently open entry's `comment` with ` | ` as separator. No-op when the result would be unchanged (e.g. empty append); errors when no entry is open.

Future commands (start, end, etc.) follow the same `timeshit.php <command>` pattern.

CLI entry-point scripts live at the project root (no `bin/` directory). PHP 8.2+ (per composer.json). Composer is dev-tooling only — runtime entry scripts use explicit `require` for the few `src/` files they need; they do not load `vendor/autoload.php`.

## Static analysis

PHPStan level 7 with `phpstan-strict-rules` must pass. Run:

```
vendor/bin/phpstan analyse
```

Type-narrowing rules learned the hard way:
- API responses (decoded JSON) are typed as `array<int|string, mixed>` at the boundary. Narrowing to a specific shape happens once, in the client, by parsing into a value object (`YoutrackIssue`). Do not chain `is_array` / `is_string` checks at every call site — push the narrowing into a parser method.
- For `cURL` calls, `curl_init()` can return `false` (must be checked); `curl_getinfo($ch, CURLINFO_HTTP_CODE)` is already typed `int` in stubs (do not cast or `is_int`-check).
- Don't use `@phpstan-ignore`, baseline entries, inline `@var` to override inferred types, `assert()`, or pointless casts to silence errors. Fix the underlying type.

## Conventions

- All secrets live under `secrets/` (gitignored). `Config::load($root)` is the single entry point for reading them.
- HTTP client wraps cURL; no Guzzle/Composer for now. Same interface can later be swapped for Guzzle if needed.
- "Current user" in YouTrack queries is implicit via the token — we use `me` keyword (e.g. `assignee: me, commenter: me, ...`).
- YouTrack custom field shapes vary (User / Enum / Period / ...). The client returns raw decoded issues; callers extract via `Client::customFieldValue($issue, $name)` and interpret the shape themselves. Field names assumed: `State`, `Type`, `Assignee`, `Category`, `Spent time` — adjust if a project uses different names.
- To know *why* an issue was downloaded, the client runs one query per role (`assignee: me`, `commenter: me`, `reporter: me`, `updater: me`, `tag: Star`, `mentions: me`) plus a `/api/workItems?author=me` call, and merges results by issue ID. Each `YoutrackIssue` carries a `roles: list<string>` with the matching subset of `assignee` / `commenter` / `reporter` / `updater` / `workAuthor` / `starred` / `mentioned`.

## Local tracking

`data/work-local.json` holds an append-only list of tracking entries. Each entry records `issueId`, `branch`, `repo`, `type`, `startedAt` / `startTrigger` and (once closed) `endedAt` / `endTrigger`. Timestamps are ISO 8601 strings (`date('c')`). The latest entry is the only one allowed to be open (`endedAt: null`). The `track` subcommand closes that open entry and opens a new one in a single write; concurrent shells therefore race, but in practice branch checkouts are serial. Not yet synced to YouTrack.

To install the post-checkout hook in another repo:

```
ln -s /home/vlasta/dev/php/timeshit/hooks/post-checkout <repo>/.git/hooks/post-checkout
```

Or set globally for all repos: `git config --global core.hooksPath /home/vlasta/dev/php/timeshit/hooks`. The hook expects `ts` on `PATH` (a script, not a shell alias — git hooks run non-interactively and do not source rc files).

## Status

- YouTrack: read-only — list issues the current user is assigned to / reported / commented on / last updated / has logged work on / starred / mentioned in, with per-issue role tags. Also pulls all work items the current user authored, and the global work-item-type list. 24h JSON caches at `data/issues.json`, `data/work-items.json`, and `data/work-item-types.json`. Run with `php timeshit.php issues` (cached), `php timeshit.php work` (work items by week/day), or `php timeshit.php refresh` (force-refresh issues, work items, and types).
- Local server: not started.
- CLI: branch-switch tracking via `track` + `hooks/post-checkout` writes to `data/work-local.json`. `start` / `end` not started. Sync to YouTrack not started.
- GitLab integration: not started.
- Browser plugin / favelet: not started.
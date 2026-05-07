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
  Config.php            loads ./config.ini + secrets/youtrack-token.txt
  YoutrackClient.php    cURL-based YouTrack REST client (parses JSON into value objects)
  YoutrackIssue.php     immutable value object representing a single YouTrack issue
secrets/                gitignored — tokens / credentials live here
  youtrack-token.txt    YouTrack permanent token (Bearer)
composer.json           dev deps: phpstan, phpstan-strict-rules; PSR-4 Timeshit\ → src/
phpstan.neon            level 7 + strict rules, paths: src/ + timeshit.php
```

## CLI

Single dispatcher script `timeshit.php` at the project root. Subcommands as positional argument:

- `php timeshit.php` — usage
- `php timeshit.php issues` — list YouTrack issues the current user is assigned to / reported / commented on / last updated

Future commands (start, end, branch-switch tracking, etc.) follow the same `timeshit.php <command>` pattern.

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

## Status

- YouTrack: read-only — list issues the current user is assigned to / reported / commented on / last updated. Run with `php timeshit.php issues`.
- Local server: not started.
- CLI (start/end/branch-switch tracking): not started.
- GitLab integration: not started.
- Browser plugin / favelet: not started.
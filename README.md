# timeshit

Personal time tracker for the CLI, integrating with YouTrack and Git.
Records what you work on, groups it by issue/type, and pushes the result to YouTrack as work items.

## Prerequisites

- PHP 8.2+ with `curl`, `intl`, `json`, `mbstring`, `posix` extensions
- Composer
- A YouTrack instance and a permanent API token
- POSIX shell

## Setup

```sh
git clone <repo> ~/dev/php/timeshit
cd ~/dev/php/timeshit
composer install
php timeshit.php
```

The first run prompts for `youtrackBaseUrl` and `youtrackToken` and writes them to `config/secrets.neon` (gitignored). 
All other config lives in `config/config.neon` (committed).

For convenient invocation, add it to your .bascrc as `ts`:

Run `ts` (or `ts help`) for the command reference.

## Connection points

### Git hook — automatic task switching on branch checkout

`hooks/post-checkout` calls `php timeshit.php checkout <branch> <repo>` on every branch switch, which closes any
open record and opens a new one tagged with the branch's issue id (parsed from the branch name).
Install per-repo or globally:

```sh
# per repo
ln -s ~/dev/php/timeshit/hooks/post-checkout <your-repo>/.git/hooks/post-checkout

# or globally for all repos
git config --global core.hooksPath ~/dev/php/timeshit/hooks
```

Update your path to the ts in the hook.

### Local HTTP server — for browser plugins / favelets

`server.php` is auto-started in the background by `timeshit.php` on every CLI invocation (unless `data/server.pid` contains `stopped`). 
It listens on `127.0.0.1:<port>` (default `1985`, configurable in `config.neon`) and accepts both plain text and HTTP `POST` 
bodies containing a single command line (e.g. `track SW-1234 Implementation`). It also runs a heartbeat that auto-closes the open record
after a suspend / hibernate / crash gap (default 10 min).

**No authentication, no CSRF protection, no origin checks — by design.** The server is bound to loopback only and is intended 
for local browser extensions / bookmarklets that POST commands while you browse a YouTrack issue or GitLab MR. 
Treat anything that can reach `127.0.0.1` on your machine as trusted. Do not expose the port externally and do not run on a multi-user host.

Only "action" commands (those that mutate records — `track`, `interrupt`, `pause`, etc.) are dispatched from the server; 
The `Access-Control-Allow-Origin: *` response header is intentional, so a favelet on any page can fire commands.

## Configuration & customization

All non-secret config lives in `config/config.neon`:

- **`timezone`, `editor`, `port`, `closedIssueRetentionDays`** — basic knobs
- **`customCommands`** — define your own subcommands on top of builtin actions (`track`, `interrupt`,
  `put`, `grab`, `days`, `pause`, `resume`, `done`, `end`, `switch`, `skip`). Each may pre-fill
  `type`, `issue`, `note`, `span`, or `day`. The stock `analyse`, `implement`, `review`, `meeting`,
  `mail`, `vacation` commands are defined here, not hardcoded — add or remove freely.
- **`commandAliases`** — flat `alias => canonical` map (no chains, no collisions with real commands)
- **`allowedTypes`, `typeAliases`, `typeColors`, `typeShortNames`** — YouTrack work-item type
  whitelist plus display tweaks
- **`interruptionTypes`, `defaultTrackType`, `defaultDayType`, `defaultIssuePrefix`, `defaultDayIssue`**
  — behavior defaults for `track` / `days`
- **`categoryAliases`, `categoryColors`, `stateAliases`, `issueStates`, `customerAliases`** — display
  tweaks for the `issues` view

Reload is automatic; the file is read on every CLI invocation.

## Data files

All runtime state lives in `data/` (gitignored):

- `records.neon` — live tracked records (the one with `endedAt: null` is the open one)
- `archive.neon` — records that were pushed to YouTrack (append-only)
- `issues.neon`, `work-items.neon`, `work-item-types.neon` — 24h NEON caches of YouTrack data
- `server.pid`, `heartbeat` — server bookkeeping

The store survives offline use: every command except `refresh` and `push` works without a network,
falling back to whatever is in the caches.

## Testing & static analysis

```sh
vendor/bin/tester tests           # nette/tester, .phpt files
vendor/bin/phpstan analyse        # level 8 + strict rules; must pass
```

See `CLAUDE.md` for the architectural deep-dive (command resolution, interruption flow, push
grouping, store semantics, type matching, span grammar, etc.).

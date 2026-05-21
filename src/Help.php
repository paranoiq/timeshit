<?php declare(strict_types=1);

namespace Timeshit;

use Closure;
use Timeshit\Util\Ansi;
use Timeshit\Util\Io;
use function array_map;
use function implode;
use function in_array;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function str_repeat;
use function str_starts_with;

final class Help
{
    public const BUILTIN_COMMAND_NAMES = [
        'status', 'issues', 'time', 'archive', 'flow',
        'track', 'interrupt', 'days', 'type', 'switch',
        'pause', 'resume', 'skip', 'grab', 'put', 'end', 'done',
        'at', 'before', 'after', 'fit', 'note', 'fix', 'edit', 'delete',
        'refresh', 'push',
        'checkout', 'server',
        'help',
    ];

    /**
     * Builtins that mutate state. The `server` command only accepts these (and
     * custom commands, which are always actions) — read-only / meta commands
     * (`status`, `issues`, `time`, `archive`, `types`, `refresh`, `help`,
     * `server`, `configure`) are dropped at the server boundary.
     */
    public const ACTION_COMMAND_NAMES = [
        'track', 'interrupt', 'checkout',
        'pause', 'resume', 'end', 'done',
        'type', 'switch', 'note',
        'days', 'skip', 'grab', 'put',
        'at', 'before', 'after', 'fit',
        'fix', 'edit', 'delete',
        'push',
    ];

    public static function print(Io $io, Config $config): void
    {
        foreach ($config->customCommandWarnings as $warning) {
            $io->err(Ansi::yellow('Warning: ' . $warning) . "\n");
        }
        if ($config->customCommandWarnings !== []) {
            $io->err("\n");
        }

        $names = self::BUILTIN_COMMAND_NAMES;
        foreach ($config->customCommands as $custom) {
            $names[] = $custom->name;
        }
        foreach ($config->commandAliases as $alias => $_canonical) {
            $names[] = $alias;
        }
        $prefixLen = self::commandPrefixLengths($names);
        $cmd = static function (string $name) use ($prefixLen): string {
            if (!isset($prefixLen[$name])) {
                return Ansi::lgreen($name);
            }
            $k = $prefixLen[$name];
            $head = mb_substr($name, 0, $k);
            $tail = mb_substr($name, $k);

            return Ansi::lgreen(Ansi::underline($head) . $tail);
        };
        /** @var array<string, list<string>> $aliasesByCanonical */
        $aliasesByCanonical = [];
        foreach ($config->commandAliases as $alias => $canonical) {
            $aliasesByCanonical[$canonical] ??= [];
            $aliasesByCanonical[$canonical][] = $alias;
        }
        $aliasHint = static function (string $canonical) use ($aliasesByCanonical, $cmd): string {
            if (!isset($aliasesByCanonical[$canonical])) {
                return '';
            }
            $rendered = array_map($cmd, $aliasesByCanonical[$canonical]);

            return ' (alias: ' . implode(', ', $rendered) . ')';
        };
        $argColor = static function(string $name, string $text): string {
            return match ($name) {
                'issue', 'id' => Ansi::yellow($text),
                'type' => Ansi::lmagenta($text),
                'note' => Ansi::lblue($text),
                default => Ansi::lyellow($text),
            };
        };
        $req = static fn(string $name): string => $argColor($name, "<{$name}>");
        $opt = static fn(string $name): string => Ansi::lblack('[') . $argColor($name, "<{$name}>") . Ansi::lblack(']');
        $flag = static fn(string $name): string => Ansi::lyellow(Ansi::underline(mb_substr($name, 0, 1)) . mb_substr($name, 1));
        $val = static fn(string $name): string => Ansi::lyellow($name);
        $app = static fn(string $name): string => Ansi::lblue($name);

        $groups = [
            'Info' => [
                [$cmd('status'),   '', 'Show current status (active entry, previous, tracked time)' . $aliasHint('status')],
                [$cmd('issues'),   $opt('search'), 'List ' . $app('YouTrack') . ' issues you are involved in (cached for 24h)' . $aliasHint('issues')],
                [$cmd('time'),     Ansi::lblack('[') . $flag('details') . Ansi::lblack(']'), 'List time entries from ' . $app('YouTrack') . ' and local entries, grouped by day/issue/type (or raw with ' . $flag('details') . ')' . $aliasHint('time')],
                [$cmd('archive'),  Ansi::lblack('[') . $flag('details') . Ansi::lblack(']'), 'List archived (already pushed) locally entries' . $aliasHint('archive')],
                [$cmd('flow'),     '', 'Show a horizontal timeline of the current week (4 min/char)' . $aliasHint('flow')],
                //[$cmd('types'),    '', 'List the ' . $app('YouTrack') . ' work-item types (cached for 24h)'],
            ],
            'Actions' => self::buildActionsHelp($config, $cmd, $req, $opt, $val, $aliasHint),
            'Edits' => [
                [$cmd('at'),       $opt('id') . ' ' . $req('time'), 'Set the start time (open entry) or end time (closed entry) of the last entry (or ' . $req('id') . ')' . $aliasHint('at')],
                [$cmd('before'),   $opt('id') . ' ' . $req('span'), 'Move the start (open) or end (closed) of the last (or ' . $req('id') . ') entry earlier by ' . $req('span') . $aliasHint('before')],
                [$cmd('after'),    $opt('id') . ' ' . $req('span'), 'Move the end of the last closed (or ' . $req('id') . ') entry later by ' . $req('span') . $aliasHint('after')],
                [$cmd('fit'),      '', 'Move the start of the open entry to match the end of the previous entry' . $aliasHint('fit')],
                [$cmd('type'),     $opt('id') . ' ' . $req('type'), 'Change the type of the current entry (or ' . $req('id') . ')' . $aliasHint('type')],
                [$cmd('note'),     $opt('id') . ' ' . $req('note'), 'Add a note to the current entry (or ' . $req('id') . ')' . $aliasHint('note')],
                [$cmd('fix'),      $opt('id') . ' ' . $req('issue'), 'Change the ' . $req('issue') . ' of the current entry (or ' . $req('id') . ')' . $aliasHint('fix')],
                [$cmd('edit'),     $req('id'),   'Open entry ' . $req('id') . ' in the configured ' . Ansi::lyellow('editor') . ' for free-form editing' . $aliasHint('edit')],
                [$cmd('delete'),   $req('id'),   'Delete entry ' . $req('id') . $aliasHint('delete')],
            ],
            'Sync' => [
                [$cmd('refresh'),  '', 'Refresh all caches from YouTrack' . $aliasHint('refresh')],
                [$cmd('push'),     $opt('date'), 'Sum closed entries by (day, issue, type) up to ' . $req('date') . ' (default: ' . $val('yesterday') . ') and create work items in ' . $app('YouTrack') . $aliasHint('push')],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'), 'Switch tracking on ' . $app('git checkout') . ' (called from ' . $app('hooks/post-checkout') . ')' . $aliasHint('checkout')],
                [$cmd('server'),   $val('start') . Ansi::lblack('|') . $val('stop'), 'Start or stop the local timeshit server (auto-started by ' . Ansi::lblue('timeshit.php') . ' unless stopped)' . $aliasHint('server')],
            ],
        ];

        $argRows = [
            [$req('issue'),  $app('YouTrack') . ' issue id, e.g. ' . $val('SW-1234') . ' or just ' . $val('1234') . ' or free text for not yet created issues'],
            [$req('id'),     'numeric entry id (the ' . Ansi::lblack('#n') . ' column shown by ' . $cmd('time') . ' / ' . $cmd('status') . ')'],
            [$req('type'),   'work-item type; see ' . Ansi::lgreen(Ansi::underline('types')) . ' for the allowed list. Default is ' . $val('defaultTrackType') . ' from config'],
            [Ansi::lblue('<note> …'), 'optional trailing note in ' . Ansi::lblue('"quotes"') . ' (set as note on the new record, joined with ' . Ansi::lblue(' | ') . ' if one already exists)'],
            [$req('span'),   'duration like ' . $val('30m') . ', ' . $val('1h 20m') . ' (units ' . $val('d') . '/' . $val('h') . '/' . $val('m') . ')'],
            [$req('time'),   $val('HH:MM') . ' or full date+time (e.g. ' . $val('2026-05-09 10:00') . ')'],
            [$req('date'),   'expressions like ' . $val('yesterday') . ' / ' . $val('yes') . ' / ' . $val('y') . ', day-of-month (e.g. ' . $val('15') . ') or full date. Default: ' . $val('today')],
            [$req('days'),   'zero, one, or two ' . $req('date') . 's. Zero: ' . $val('today') . '; one: that single date; two: weekday range (inclusive, weekends skipped)'],
            //[$req('branch'), 'git branch name (used to extract the issue id; passed by git hook)'],
            //[$req('repo'),   'repository name (passed by git hook)'],
            //[$req('search'), 'case-insensitive substring matched against issue title and description'],
        ];

        $nameWidth = 0;
        $argsWidth = 0;
        foreach ($groups as $rows) {
            foreach ($rows as [$name, $args]) {
                $nameWidth = max($nameWidth, Ansi::length($name));
                $argsWidth = max($argsWidth, Ansi::length($args));
            }
        }
        $argNameWidth = 0;
        foreach ($argRows as [$name]) {
            $argNameWidth = max($argNameWidth, Ansi::length($name));
        }

        $io->out(Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + Git + GitLab') . "\n\n");
        $io->out("Usage: " . Ansi::lblue('timeshit.php') . " " . $cmd('command') . " " . $opt('args') . "\n\n");
        foreach ($groups as $title => $rows) {
            $io->out(Ansi::lwhite($title) . ":\n");
            foreach ($rows as [$name, $args, $desc]) {
                $io->out("  " . $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2)
                    . $args . str_repeat(' ', $argsWidth - Ansi::length($args) + 2)
                    . $desc . "\n");
            }
        }
        $io->out("\n" . Ansi::lwhite('Arguments') . ":\n");
        foreach ($argRows as [$name, $desc]) {
            $io->out("  " . $name . str_repeat(' ', $argNameWidth - Ansi::length($name) + 2) . $desc . "\n");
        }

        $io->out("\nConfiguration: see " . Ansi::lblue('config/config.neon') . " and " . Ansi::lblue('config/secrets.neon') . "\n");
        $io->out("Custom commands and aliases: see customCommands and commandAliases in " . Ansi::lblue('config/config.neon') . "\n");
        $io->out("Local data: " . Ansi::lblue('data/records.neon') . " and " . Ansi::lblue('data/archive.neon') . " (edit by hand or via " . $cmd('edit') . " " . $req('id') . ")\n");
        $io->out("Server: listens on " . Ansi::lyellow("127.0.0.1:{$config->port}") . " (action commands as plain TCP or HTTP POST)\n");
    }

    /**
     * For each command name, computes the shortest prefix length that
     * `Resolver::matchCommand` resolves uniquely to that command. Used by
     * `print` to underline the typeable shortcut. When no shorter prefix
     * works (because every shorter prefix is either ambiguous or exactly
     * matches a different command), the full name is used — the user has to
     * type the whole word.
     *
     * @param list<string> $names full command list (builtins + custom)
     * @return array<string, int>
     */
    private static function commandPrefixLengths(array $names): array
    {
        $result = [];
        foreach ($names as $cmd) {
            $lc = mb_strtolower($cmd);
            $len = mb_strlen($cmd);
            $minK = $len;
            for ($k = 1; $k < $len; $k++) {
                $prefix = mb_substr($lc, 0, $k);
                $exactConflict = false;
                $matchCount = 0;
                foreach ($names as $other) {
                    $olc = mb_strtolower($other);
                    if ($other !== $cmd && $olc === $prefix) {
                        $exactConflict = true;
                        break;
                    }
                    if (str_starts_with($olc, $prefix)) {
                        $matchCount++;
                    }
                }
                if ($exactConflict) {
                    continue;
                }
                if ($matchCount === 1) {
                    $minK = $k;
                    break;
                }
            }
            $result[$cmd] = $minK;
        }

        return $result;
    }

    /**
     * @param Closure(string): string $cmd
     * @param Closure(string): string $req
     * @param Closure(string): string $opt
     * @param Closure(string): string $val
     * @param Closure(string): string $aliasHint
     * @return list<array{string, string, string}>
     */
    private static function buildActionsHelp(Config $config, Closure $cmd, Closure $req, Closure $opt, Closure $val, Closure $aliasHint): array
    {
        $note = Ansi::lblue('…');

        /** @var array<string, list<array{string, string, string}>> $customByParent */
        $customByParent = [];
        foreach ($config->customCommands as $custom) {
            $customByParent[$custom->parent] ??= [];
            $argParts = [];
            if (in_array($custom->parent, ['track', 'interrupt', 'put', 'grab'], true) && $custom->issue === '') {
                $argParts[] = $req('issue');
            }
            if (in_array($custom->parent, ['put', 'grab', 'skip'], true) && $custom->span === '') {
                $argParts[] = $req('span');
            }
            if ($custom->parent === 'days') {
                if ($custom->day === '') {
                    $argParts[] = $opt('days');
                }
                if ($custom->issue === '') {
                    $argParts[] = $opt('issue');
                }
                if ($custom->type === '') {
                    $argParts[] = $opt('type');
                }
            }
            $argParts[] = $note;
            $desc = 'Like ' . $cmd($custom->parent);
            $bits = [];
            if ($custom->type !== '') {
                $bits[] = Ansi::lmagenta($custom->type);
            }
            if ($custom->issue !== '') {
                $bits[] = 'on ' . Ansi::lyellow($custom->issue);
            }
            if ($custom->span !== '') {
                $bits[] = 'span ' . Ansi::lyellow($custom->span);
            }
            if ($custom->day !== '') {
                $bits[] = 'day ' . Ansi::lyellow($custom->day);
            }
            if ($custom->note !== '') {
                $bits[] = 'note ' . Ansi::lblue('"' . $custom->note . '"');
            }
            if ($bits !== []) {
                $desc .= ', but with ' . implode(', ', $bits);
            }
            $customByParent[$custom->parent][] = [' ' . $cmd($custom->name), implode(' ', $argParts), $desc . $aliasHint($custom->name)];
        }

        $appendCustoms = static function (array &$rows, string $parent) use (&$customByParent): void {
            foreach ($customByParent[$parent] ?? [] as $row) {
                $rows[] = $row;
            }
        };

        $rows = [];
        $rows[] = [$cmd('track'), $req('issue') . ' ' . $opt('type') . ' ' . $note, 'Start tracking of ' . $req('issue') . $aliasHint('track')];
        $appendCustoms($rows, 'track');
        $rows[] = [$cmd('interrupt'), $req('issue') . ' ' . $opt('type') . ' ' . $note, 'Like ' . $cmd('track') . ', but marks the currently open entry as paused (auto-resumed by ' . $cmd('done') . ')' . $aliasHint('interrupt')];
        $appendCustoms($rows, 'interrupt');
        $rows[] = [$cmd('pause'), $note, 'Pause the current entry' . $aliasHint('pause')];
        $appendCustoms($rows, 'pause');
        $rows[] = [$cmd('resume'), $note, 'Resume tracking from the most recent entry' . $aliasHint('resume')];
        $appendCustoms($rows, 'resume');
        $appendCustoms($rows, 'continue');
        $rows[] = [$cmd('done'), $note, 'End the current entry and resume the most recently interrupted one' . $aliasHint('done')];
        $appendCustoms($rows, 'done');
        $rows[] = [$cmd('end'), $note, 'End the current entry (do not resume any)' . $aliasHint('end')];
        $appendCustoms($rows, 'end');
        $rows[] = [$cmd('switch'), $req('type') . ' ' . $note, 'Switch current entry to different ' . $req('type') . ' from now' . $aliasHint('switch')];
        $appendCustoms($rows, 'switch');
        $rows[] = [$cmd('skip'), $req('span') . ' ' . $note, 'Skip time ' . $req('span') . ' from current entry' . $aliasHint('skip')];
        $appendCustoms($rows, 'skip');
        $rows[] = [$cmd('grab'), $req('issue') . ' ' . $req('span') . ' ' . $opt('type') . ' ' . $note, 'Grab a ' . $req('span') . '-long time from the open entry and fill it with ' . $req('issue') . $aliasHint('grab')];
        $appendCustoms($rows, 'grab');
        $rows[] = [$cmd('put'), $req('issue') . ' ' . $req('span') . ' ' . $opt('type') . ' ' . $note, 'Add a ' . $req('span') . '-long entry for ' . $req('issue') . ' without tracking' . $aliasHint('put')];
        $appendCustoms($rows, 'put');
        $rows[] = [$cmd('days'), $opt('days') . ' ' . $opt('issue') . ' ' . $opt('type') . ' ' . $note, 'Log a full 8h day on ' . $req('days') . ' (default: ' . $val('today') . '). Default ' . $req('issue') . ' is ' . $val('defaultDayIssue') . ', ' . $req('type') . ' is ' . $val('defaultDayType') . $aliasHint('days')];
        $appendCustoms($rows, 'days');

        return $rows;
    }
}
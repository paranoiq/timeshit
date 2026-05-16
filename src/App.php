<?php declare(strict_types=1);

namespace Timeshit;

use Closure;
use Collator;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Nette\Neon\Neon;
use RuntimeException;
use Throwable;
use Timeshit\Local\FileRecordStore;
use Timeshit\Local\Record;
use Timeshit\Local\RecordStore;
use Timeshit\Util\Ansi;
use Timeshit\Util\Clock;
use Timeshit\Util\Io;
use Timeshit\Util\SystemClock;
use Timeshit\View\AllView;
use Timeshit\View\IssuesView;
use Timeshit\View\RecordsView;
use Timeshit\Youtrack\CachedIssueDataProvider;
use Timeshit\Youtrack\CachedTypeProvider;
use Timeshit\Youtrack\HttpWorkItemPusher;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\IssueCache;
use Timeshit\Youtrack\IssueDataProvider;
use Timeshit\Youtrack\TypeProvider;
use Timeshit\Youtrack\WorkItemCache;
use Timeshit\Youtrack\WorkItemPusher;
use Timeshit\Youtrack\WorkItemType;
use Timeshit\Youtrack\WorkItemTypeCache;
use Timeshit\Youtrack\YoutrackClient;
use function array_filter;
use function array_merge;
use function array_values;
use function count;
use function ctype_digit;
use function date_default_timezone_set;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function fwrite;
use function proc_close;
use function proc_open;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function posix_kill;
use function is_int;
use function ksort;
use function max;
use function mb_strimwidth;
use function mb_strlen;
use function mb_strtolower;
use function mb_strwidth;
use function mb_substr;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_repeat;
use function stream_socket_client;
use function str_starts_with;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usort;

final class App
{

    private const ISSUES_CACHE_FILE = '/data/issues.neon';
    private const WORK_ITEMS_CACHE_FILE = '/data/work-items.neon';
    private const WORK_ITEM_TYPES_FILE = '/data/work-item-types.neon';
    private const RECORDS_FILE = '/data/records.neon';
    private const ARCHIVE_FILE = '/data/archive.neon';
    private const SERVER_PID_FILE = '/data/server.pid';
    private const SERVER_SCRIPT = '/server.php';

    public function __construct(
        private readonly Config $config,
        private readonly RecordStore $store,
        private readonly TypeProvider $types,
        private readonly IssueDataProvider $issueData,
        private readonly Clock $clock,
        private readonly Io $io,
        private readonly Configurator $configurator,
        private readonly WorkItemPusher $pusher,
        private readonly string $serverPidFile,
        private readonly string $serverScript,
    ) {}

    /** Wires the file/network-backed implementations from a project root directory. */
    public static function forRoot(string $rootDir, Config $config, Io $io): self
    {
        $client = new YoutrackClient($config->youtrackBaseUrl, $config->youtrackToken);
        $types = new CachedTypeProvider(
            new WorkItemTypeCache($rootDir . self::WORK_ITEM_TYPES_FILE),
            $client,
            $io,
        );
        $issueData = new CachedIssueDataProvider(
            new IssueCache($rootDir . self::ISSUES_CACHE_FILE),
            new WorkItemCache($rootDir . self::WORK_ITEMS_CACHE_FILE),
            $client,
            $io,
            $config->youtrackBaseUrl,
        );

        return new self(
            config: $config,
            store: new FileRecordStore($rootDir . self::RECORDS_FILE, $rootDir . self::ARCHIVE_FILE),
            types: $types,
            issueData: $issueData,
            clock: new SystemClock(),
            io: $io,
            configurator: new Configurator($rootDir, $io),
            pusher: new HttpWorkItemPusher($client),
            serverPidFile: $rootDir . self::SERVER_PID_FILE,
            serverScript: $rootDir . self::SERVER_SCRIPT,
        );
    }

    private const COMMAND_NAMES = [
        'status', 'issues', 'time', 'archive', //'types',
        'track', 'interrupt', 'meeting', 'mail', 'review', 'test', 'implement', 'analyse', 'design', 'day', 'vacation', 'type', 'switch',
        'pause', 'resume', 'continue', 'skip', 'grab', 'put', 'end', 'done',
        'at', 'before', 'after', 'note', 'edit', 'delete',
        'refresh', 'push',
        'checkout', 'server',
        //'configure',
        'help',
    ];

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $input = $argv[1] ?? null;

        if ($input === null || $input === '' || $input === '-h' || $input === '--help') {
            self::printHelp($this->io, $this->config);

            return 0;
        }

        date_default_timezone_set($this->config->timezone);

        try {
            $resolved = Resolver::matchCommand($input, self::COMMAND_NAMES);
        } catch (RuntimeException $e) {
            $this->ambiguousCommand($e->getMessage());
        }
        if ($resolved === null) {
            $this->unknownCommand($input);
        }

        if ($resolved !== 'server') {
            $this->maybeAutoStartServer();
        }

        try {
            match ($resolved) {
                'help' => self::printHelp($this->io, $this->config),
                'issues' => $this->cmdIssues(Resolver::restArgs($argv)),
                'time' => $this->cmdTime($argv[2] ?? null),
                'archive' => $this->cmdArchive($argv[2] ?? null),
                'status' => $this->cmdStatus(),
                'refresh' => $this->cmdRefresh(),
                'track' => $this->cmdTrack($argv[2] ?? null, $argv[3] ?? null),
                'interrupt' => $this->cmdInterrupt($argv[2] ?? null, $argv[3] ?? null),
                'meeting' => $this->cmdMeeting(Resolver::restArgs($argv)),
                'mail' => $this->cmdMail(Resolver::restArgs($argv)),
                'review' => $this->cmdReview($argv[2] ?? null),
                'test' => $this->cmdTest($argv[2] ?? null),
                'implement' => $this->cmdImplement($argv[2] ?? null),
                'analyse' => $this->cmdAnalyse($argv[2] ?? null),
                'design' => $this->cmdDesign($argv[2] ?? null),
                'day' => $this->cmdDay($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'vacation' => $this->cmdVacation($argv[2] ?? null, $argv[3] ?? null),
                'checkout' => $this->cmdCheckout($argv[2] ?? null, $argv[3] ?? null),
                'type' => $this->cmdType(Resolver::restArgs($argv)),
                'types' => $this->cmdTypes(),
                'switch' => $this->cmdSwitch($argv[2] ?? null),
                'pause' => $this->cmdPause(Resolver::restArgs($argv)),
                'resume', 'continue' => $this->cmdResume(),
                'skip' => $this->cmdSkip($argv[2] ?? null),
                'grab' => $this->cmdGrab($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'put' => $this->cmdPut($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'end' => $this->cmdEnd(Resolver::restArgs($argv)),
                'done' => $this->cmdDone(Resolver::restArgs($argv)),
                'note' => $this->cmdNote(Resolver::restArgs($argv)),
                'edit' => $this->cmdEdit($argv[2] ?? null),
                'delete' => $this->cmdDelete($argv[2] ?? null),
                'at' => $this->cmdAt(Resolver::restArgs($argv)),
                'before' => $this->cmdBefore(Resolver::restArgs($argv)),
                'after' => $this->cmdAfter(Resolver::restArgs($argv)),
                'push' => $this->cmdPush($argv[2] ?? null),
                'server' => $this->cmdServer($argv[2] ?? null),
                'configure' => $this->configurator->run(),
                default => throw new RuntimeException("dispatch: no handler for resolved command '{$resolved}'"),
            };
        } catch (Throwable $e) {
            $this->io->err(Ansi::red("Error: " . $e->getMessage()) . "\n");

            return 1;
        }

        return 0;
    }

    private function unknownCommand(string $command): never
    {
        $this->io->err(Ansi::red("Unknown command: {$command}") . "\n\n");
        self::printHelp($this->io, $this->config);
        exit(1);
    }

    private function ambiguousCommand(string $message): never
    {
        $this->io->err(Ansi::red($message) . "\n\n");
        self::printHelp($this->io, $this->config);
        exit(1);
    }

    /**
     * For each entry in `COMMAND_NAMES`, computes the shortest prefix length
     * that `Resolver::matchCommand` resolves uniquely to that command. The
     * result is used by `printHelp` to underline the typeable shortcut. When
     * no shorter prefix works (because every shorter prefix is either
     * ambiguous or exactly matches a different command), the full name is
     * used — the user has to type the whole word.
     *
     * @return array<string, int>
     */
    private static function commandPrefixLengths(): array
    {
        $result = [];
        foreach (self::COMMAND_NAMES as $cmd) {
            $lc = mb_strtolower($cmd);
            $len = mb_strlen($cmd);
            $minK = $len;
            for ($k = 1; $k < $len; $k++) {
                $prefix = mb_substr($lc, 0, $k);
                $exactConflict = false;
                $matchCount = 0;
                foreach (self::COMMAND_NAMES as $other) {
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

    public static function printHelp(Io $io, Config $config): void
    {
        $prefixLen = self::commandPrefixLengths();
        $cmd = static function (string $name) use ($prefixLen): string {
            if (!isset($prefixLen[$name])) {
                return Ansi::lgreen($name);
            }
            $k = $prefixLen[$name];
            $head = mb_substr($name, 0, $k);
            $tail = mb_substr($name, $k);

            return Ansi::lgreen(Ansi::underline($head) . $tail);
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
                [$cmd('status'),   '', 'Show current status (active entry, previous, tracked time)'],
                [$cmd('issues'),   $opt('search'), 'List ' . $app('YouTrack') . ' issues you are involved in (cached for 24h)'],
                [$cmd('time'),     Ansi::lblack('[') . $flag('details') . Ansi::lblack(']'), 'List time entries from ' . $app('YouTrack') . ' and locally tracked records, grouped by day/issue/type (not grouped with ' . $flag('details') . ')'],
                [$cmd('archive'),  Ansi::lblack('[') . $flag('details') . Ansi::lblack(']'), 'List archived (already pushed) locally tracked entries'],
                //[$cmd('types'),    '', 'List the ' . $app('YouTrack') . ' work-item types (cached for 24h)'],
            ],
            'Actions' => [
                [$cmd('track'),    $req('issue') . ' ' . $opt('type'), 'Start tracking of ' . $req('issue')],
                [' ' . $cmd('analyse'),  $req('issue'), 'Like ' . $cmd('track') . ', but with ' . Ansi::lyellow('defaultAnalyseType') . ' from config'],
                [' ' . $cmd('design'),   $req('issue'), 'Like ' . $cmd('track') . ', but with ' . Ansi::lyellow('defaultDesignType') . ' from config'],
                [' ' . $cmd('implement'),$req('issue'), 'Like ' . $cmd('track') . ', but with ' . Ansi::lyellow('defaultImplementType') . ' from config'],
                [' ' . $cmd('review'),   $req('issue'), 'Like ' . $cmd('track') . ', but with ' . Ansi::lyellow('defaultReviewType') . ' from config'],
                [' ' . $cmd('test'),     $req('issue'), 'Like ' . $cmd('track') . ', but with ' . Ansi::lyellow('defaultTestType') . ' from config'],
                [$cmd('interrupt'),$req('issue') . ' ' . $opt('type'), 'Like ' . $cmd('track') . ', but marks the currently open entry as paused (auto-resumed by ' . $cmd('done') . ')'],
                [' ' . $cmd('meeting'),  $opt('note'), 'Like ' . $cmd('interrupt') . ', but with ' . Ansi::lyellow('defaultMeetingIssue') . ' / ' . Ansi::lyellow('defaultMeetingType') . ' from config'],
                [' ' . $cmd('mail'),     $opt('note'), 'Like ' . $cmd('interrupt') . ', but with ' . Ansi::lyellow('defaultMailIssue') . ' / ' . Ansi::lyellow('defaultMailType') . ' from config'],
                [$cmd('pause'),    $opt('note'), 'Pause the current entry'],
                [$cmd('resume'),   '', 'Resume tracking from the most recent entry (alias: ' . $cmd('continue') . ')'],
                [$cmd('done'),     $opt('note'), 'End the current entry and resume the most recently interrupted one'],
                [$cmd('end'),      $opt('note'), 'End the current entry (do not resume any)'],
                [$cmd('switch'),   $req('type'), 'Switch current entry to different ' . $req('type') . ' from now'],
                [$cmd('skip'),     $req('span'), 'Skip time ' . $req('span') . ' from currentl entry'],
                [$cmd('grab'),     $req('issue') . ' ' . $req('span') . ' ' . $opt('type'), 'Grab a ' . $req('span') . '-long time from the open entry and fill it with ' . $req('issue')],
                [$cmd('put'),      $req('issue') . ' ' . $req('span') . ' ' . $opt('type'), 'Add a ' . $req('span') . '-long entry for ' . $req('issue') . ' without tracking'],
                [$cmd('day'),      $req('issue') . ' ' . $opt('date') . ' ' . $opt('type'), 'Log a full 8h day for ' . $req('issue') . ' on ' . $req('date') . ', default type is ' . $val('defaultDayType') . ' from config'],
                [$cmd('vacation'), $req('date') . ' ' . $req('date'), 'Log a full 8h day on every working day between the two ' . $req('date') . 's (inclusive)'],
            ],
            'Edits' => [
                [$cmd('at'),       $opt('id') . ' ' . $req('time'), 'Set the start time (open entry) or end time (closed entry) of the last entry (or ' . $req('id') . ')'],
                [$cmd('before'),   $opt('id') . ' ' . $req('span'), 'Move the start (open) or end (closed) of the last (or ' . $req('id') . ') entry earlier by ' . $req('span')],
                [$cmd('after'),    $opt('id') . ' ' . $req('span'), 'Move the end of the last closed (or ' . $req('id') . ') entry later by ' . $req('span')],
                [$cmd('type'),     $opt('id') . ' ' . $req('type'), 'Change the type of the current entry (or ' . $req('id') . ')'],
                [$cmd('note'),     $opt('id') . ' ' . $req('note'), 'Add a note to the current entry (or ' . $req('id') . ')'],
                [$cmd('edit'),     $req('id'),   'Open entry ' . $req('id') . ' in the configured ' . Ansi::lyellow('editor') . ' for free-form editing'],
                [$cmd('delete'),   $req('id'),   'Delete entry ' . $req('id')],
            ],
            'Sync' => [
                [$cmd('refresh'),  '', 'Refresh all caches from YouTrack'],
                [$cmd('push'),     $opt('date'), 'Sum closed entries by (day, issue, type) up to ' . $req('date') . ' (default: ' . $val('yesterday') . ') and create work items in ' . $app('YouTrack')],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'), 'Switch tracking on ' . $app('git checkout') . ' (called from ' . $app('hooks/post-checkout') . ')'],
                [$cmd('server'),   $val('start') . Ansi::lblack('|') . $val('stop'), 'Start or stop the local timeshit server (auto-started by ' . Ansi::lblue('timeshit.php') . ' unless stopped)'],
            ],
        ];

        $argRows = [
            [$req('issue'),  $app('YouTrack') . ' issue id, e.g. ' . $val('SW-1234') . ' or just ' . $val('1234') . ' or free text for not yet created issues'],
            [$req('id'),     'numeric entry id (the ' . Ansi::lblack('#n') . ' column shown by ' . $cmd('time') . ' / ' . $cmd('status') . ')'],
            [$req('type'),   'work-item type; see ' . Ansi::lgreen(Ansi::underline('types')) . ' for the allowed list. Default is ' . $val('defaultTrackType') . ' from config'],
            [$req('note'),   'free-form text; appended to the entrie\'s existing note'],
            [$req('span'),   'duration like ' . $val('30m') . ', ' . $val('1h 20m') . ' (units ' . $val('d') . '/' . $val('h') . '/' . $val('m') . ')'],
            [$req('time'),   $val('HH:MM') . ' or full date+time (e.g. ' . $val('2026-05-09 10:00') . ')'],
            [$req('date'),   'expressions like ' . $val('yesterday') . ' / ' . $val('yes') . ' / ' . $val('y') . ', day-of-month (e.g. ' . $val('15') . ') or full date. Default: ' . $val('today')],
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

        $io->out("\nConfiguration: see config/config.neon and config/secrets.neon\n");
    }

    private function cmdIssues(?string $search): void
    {
        $data = $this->issueData->loadOrFetch();
        $issues = $data['issues'];
        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $issues = array_values(array_filter(
                $issues,
                static fn(Issue $i): bool => str_contains(mb_strtolower($i->id), $needle)
                    || str_contains(mb_strtolower($i->title), $needle)
                    || str_contains(mb_strtolower($i->description), $needle)
                    || str_contains(mb_strtolower($i->assignee), $needle)
                    || str_contains(mb_strtolower(implode(' ', $i->customers)), $needle),
            ));
        }
        (new IssuesView($this->config->youtrackBaseUrl, $data['user']))->render($issues, $data['workItems'], $this->store->load());
    }

    private function cmdTime(?string $modifier): void
    {
        $details = self::resolveDetailsFlag('time', $modifier);
        $data = $this->issueData->loadOrFetch();
        $records = $this->store->load();
        (new AllView($this->config->youtrackBaseUrl))->render($data['workItems'], $records, $data['issues'], $details);
    }

    private function cmdArchive(?string $modifier): void
    {
        $details = self::resolveDetailsFlag('archive', $modifier);
        (new RecordsView($this->config->youtrackBaseUrl))->render($this->store->loadArchive(), $this->issueData->titles(), $details);
    }

    private static function resolveDetailsFlag(string $cmd, ?string $modifier): bool
    {
        if ($modifier === null || $modifier === '') {
            return false;
        }
        if (Resolver::matchCommand($modifier, ['details']) === null) {
            throw new RuntimeException("{$cmd}: unknown modifier '{$modifier}' (expected 'details')");
        }

        return true;
    }

    private function cmdStatus(): void
    {
        $items = $this->store->load();
        $active = null;
        $previous = null;
        for ($i = count($items) - 1; $i >= 0; $i--) {
            $r = $items[$i];
            if ($r->status === 'day') {
                continue;
            }
            if ($r->isOpen()) {
                if ($active === null) {
                    $active = $r;
                }
                continue;
            }
            $previous = $r;
            break;
        }
        if ($active === null && $previous === null) {
            $this->io->out("No tracking entries.\n");
        } else {
            $today = $this->clock->now()->format('Y-m-d');
            $weekStart = $this->clock->now()->modify('monday this week')->format('Y-m-d');
            $now = $this->clock->nowMinute();
            $todayMinutes = 0;
            $weekMinutes = 0;
            foreach (array_merge($this->store->loadArchive(), $items) as $r) {
                if ($r->status === 'untracked' || $r->status === 'day') {
                    continue;
                }
                $day = substr($r->startedAt, 0, 10);
                if ($day < $weekStart) {
                    continue;
                }
                $end = $r->endedAt ?? $now;
                try {
                    $s = new DateTimeImmutable($r->startedAt);
                    $e = new DateTimeImmutable($end);
                } catch (Exception) {
                    continue;
                }
                $minutes = max(0, intdiv($e->getTimestamp() - $s->getTimestamp(), 60));
                $weekMinutes += $minutes;
                if ($day === $today) {
                    $todayMinutes += $minutes;
                }
            }
            $isWeekend = (int) $this->clock->now()->format('N') >= 6;
            $todayColor = match (true) {
                $isWeekend => Ansi::yellow(...),
                $todayMinutes >= 8 * 60 => Ansi::lgreen(...),
                default => Ansi::red(...),
            };
            $weekColor = $weekMinutes >= 40 * 60 ? Ansi::lgreen(...) : Ansi::red(...);
            $this->io->out(Ansi::lwhite('Today') . ": " . Format::spent($todayMinutes, $todayColor, false) . "\n");
            $this->io->out(Ansi::lwhite('Week') . ":  " . Format::spent($weekMinutes, $weekColor, false) . "\n\n");

            $baseUrl = rtrim($this->config->youtrackBaseUrl, '/');
            $titleByIssueId = $this->issueData->titles();
            if ($active !== null) {
                $this->io->out(Ansi::lyellow('Active:') . "\n");
                $this->io->out($this->statusLine($active, $baseUrl, $titleByIssueId) . "\n");
            } else {
                $this->io->out(Ansi::lblack('No active entry.') . "\n");
            }
            if ($previous !== null) {
                $this->io->out("\n" . Ansi::lyellow('Previous:') . "\n");
                $this->io->out($this->statusLine($previous, $baseUrl, $titleByIssueId) . "\n");
            }
        }

        $uptime = $this->probeServerUptime();
        if ($uptime === null) {
            $this->io->out("\n" . Ansi::lblack('timeshit server is not running') . "\n");
        } else {
            $this->io->out("\n" . Ansi::lgreen('timeshit server running for ' . Format::minutes(intdiv($uptime, 60))) . "\n");
        }
    }

    private function probeServerUptime(): ?int
    {
        $client = @stream_socket_client('tcp://127.0.0.1:1885', $errno, $errstr, 0.5);
        if ($client === false) {
            return null;
        }
        fwrite($client, 'uptime');
        $response = trim((string) fread($client, 1024));
        fclose($client);
        if ($response === '' || !ctype_digit($response)) {
            return null;
        }

        return (int) $response;
    }

    private function cmdServer(?string $action): void
    {
        if ($action === null || $action === '') {
            throw new RuntimeException("server: missing action (expected 'start' or 'stop')");
        }
        try {
            $resolved = Resolver::matchCommand($action, ['start', 'stop']);
        } catch (RuntimeException $e) {
            throw new RuntimeException("server: " . $e->getMessage());
        }
        if ($resolved === null) {
            throw new RuntimeException("server: unknown action '{$action}' (expected 'start' or 'stop')");
        }
        match ($resolved) {
            'start' => $this->cmdServerStart(),
            'stop' => $this->cmdServerStop(),
            default => throw new RuntimeException("server: no handler for resolved action '{$resolved}'"),
        };
    }

    private function cmdServerStart(): void
    {
        $runningPid = $this->runningServerPid();
        if ($runningPid !== null) {
            $this->io->out(Ansi::lblack("timeshit server is already running (pid {$runningPid})") . "\n");

            return;
        }
        $this->spawnServer();
        $this->io->out("timeshit server started\n");
    }

    private function spawnServer(): void
    {
        if (is_file($this->serverPidFile)) {
            unlink($this->serverPidFile);
        }
        exec('setsid ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->serverScript) . ' < /dev/null > /dev/null 2>&1 &');
    }

    /**
     * Auto-recovery: if the pid file exists, was not put into the explicit
     * `'stopped'` state by `server stop`, and the pid no longer points at a
     * live process (crash / OS kill / reboot), respawn the server. Missing
     * pid file → never started; do nothing.
     */
    private function maybeAutoStartServer(): void
    {
        if (!is_file($this->serverPidFile)) {
            return;
        }
        $contents = file_get_contents($this->serverPidFile);
        if ($contents === false) {
            return;
        }
        if (trim($contents) === 'stopped') {
            return;
        }
        if ($this->runningServerPid() !== null) {
            return;
        }
        $this->spawnServer();
        $this->io->err(Ansi::lblack('timeshit server auto-restarted') . "\n");
    }

    private function cmdServerStop(): void
    {
        $runningPid = $this->runningServerPid();
        if ($runningPid === null) {
            if (is_file($this->serverPidFile) && trim((string) file_get_contents($this->serverPidFile)) === 'stopped') {
                $this->io->out(Ansi::lblack('timeshit server is already stopped') . "\n");
            } else {
                file_put_contents($this->serverPidFile, 'stopped');
                $this->io->out("timeshit server stopped\n");
            }

            return;
        }
        // Write the sentinel before killing so the server's shutdown handler leaves it alone.
        file_put_contents($this->serverPidFile, 'stopped');
        posix_kill($runningPid, 15);
        $this->io->out("timeshit server stopped (pid {$runningPid})\n");
    }

    private function runningServerPid(): ?int
    {
        if (!is_file($this->serverPidFile)) {
            return null;
        }
        $contents = file_get_contents($this->serverPidFile);
        if ($contents === false) {
            return null;
        }
        $value = trim($contents);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $pid = (int) $value;
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            return null;
        }

        return $pid;
    }

    /** `SW-1234 #25 Issue title` — clickable issue id, dim record id, default-color title (if known). For inline action messages. */
    private function actionRef(string $issueId, int $recordId): string
    {
        $url = rtrim($this->config->youtrackBaseUrl, '/') . '/issue/' . $issueId;
        $ref = Ansi::link($url, $issueId) . ' ' . Format::recordId($recordId);
        $title = $this->issueData->titles()[$issueId] ?? '';
        if ($title !== '') {
            $ref .= ' ' . $title;
        }

        return $ref;
    }

    /** @param array<string, string> $titleByIssueId */
    private function statusLine(Record $r, string $baseUrl, array $titleByIssueId): string
    {
        $today = $this->clock->now()->format('Y-m-d');
        $startDate = substr($r->startedAt, 0, 10);
        $startStr = $startDate === $today ? substr($r->startedAt, 11) : $r->startedAt;

        if ($r->endedAt === null) {
            $now = $this->clock->nowMinute();
            $minutes = max(0, intdiv((new DateTimeImmutable($now))->getTimestamp() - (new DateTimeImmutable($r->startedAt))->getTimestamp(), 60));
            $duration = Format::spent($minutes, null, false) . Ansi::lblack('so far');
            $timeRange = $startStr . ' → ' . Ansi::lgreen('…');
        } else {
            $endDate = substr($r->endedAt, 0, 10);
            $endStr = $endDate === $today && $startDate === $today
                ? substr($r->endedAt, 11)
                : $r->endedAt;
            $minutes = max(0, intdiv((new DateTimeImmutable($r->endedAt))->getTimestamp() - (new DateTimeImmutable($r->startedAt))->getTimestamp(), 60));
            $duration = Format::spent($minutes, null, false);
            $timeRange = $startStr . Ansi::lblack('–') . $endStr;
        }

        $url = $baseUrl . '/issue/' . $r->issueId;
        $line = sprintf(
            '  %s %s  %s  %s  %s  %s',
            Ansi::link($url, $r->issueId),
            Format::recordId($r->id),
            Format::type($r->type),
            $timeRange,
            Ansi::lblack('(') . $duration . Ansi::lblack(')'),
            Format::status($r->status),
        );
        $title = $titleByIssueId[$r->issueId] ?? '';
        if ($title !== '') {
            $line .= "\n      " . $title;
        }
        if ($r->note !== '') {
            $line .= "\n      " . Ansi::lblack('"' . $r->note . '"');
        }
        if ($r->log !== '') {
            $line .= "\n      " . Ansi::lblack($r->log);
        }

        return $line;
    }

    /** @param list<Record> $items */
    private static function findRecordIndex(array $items, int $id): ?int
    {
        foreach ($items as $i => $r) {
            if ($r->id === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Within a `RecordStore` transaction, find the record with the given id, reject `day` entries, and
     * apply `$modifier` to it. The modifier returns the replacement `Record` (saved in place) or `null`
     * (no-op). Returns `[$previous, $updated]` on change, or `null` if the modifier opted out.
     *
     * @param Closure(Record): ?Record $modifier
     * @return ?array{0: Record, 1: Record}
     */
    private function modifyRecordById(string $cmd, int $id, Closure $modifier): ?array
    {
        return $this->store->transaction(function () use ($cmd, $id, $modifier): ?array {
            $items = $this->store->load();
            $index = self::findRecordIndex($items, $id);
            if ($index === null) {
                throw new RuntimeException("{$cmd}: entry #{$id} not found");
            }
            $current = $items[$index];
            if ($current->status === 'day') {
                throw new RuntimeException("{$cmd}: refusing to edit day entry #{$id}");
            }
            $updated = $modifier($current);
            if ($updated === null) {
                return null;
            }
            $items[$index] = $updated;
            $this->store->save(array_values($items));

            return [$current, $updated];
        });
    }

    private function cmdTypes(): void
    {
        $types = $this->types->types();
        $collator = new Collator('cs_CZ');
        $collator->setStrength(Collator::SECONDARY);
        usort($types, static function (WorkItemType $a, WorkItemType $b) use ($collator): int {
            $cmp = $collator->compare($a->name, $b->name);

            return $cmp === false ? 0 : $cmp;
        });
        $nameWidth = 0;
        foreach ($types as $t) {
            $nameWidth = max($nameWidth, Ansi::length($t->name));
        }
        foreach ($types as $t) {
            $name = in_array($t->name, $this->config->allowedTypes, true) ? Ansi::lgreen($t->name) : $t->name;
            $this->io->out($name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2) . Ansi::lblack($t->id) . "\n");
        }
    }

    private function cmdRefresh(): void
    {
        $this->issueData->refresh();
        $this->types->refresh();
    }

    private function cmdType(?string $rest): void
    {
        [$id, $rest] = Resolver::peelRecordId('type', $rest);
        $matched = Resolver::resolveType('type', $rest, null, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
        if ($id === null) {
            $result = $this->store->changeOpenType($matched, $this->clock->nowMinute(), 'type');
            $item = $result['item'];
            if ($item === null) {
                throw new RuntimeException('type: no open tracking entry to update');
            }
            if (!$result['changed']) {
                return;
            }
            $this->io->err(sprintf(
                "Changed type of %s %s from %s to %s\n",
                $item->issueId,
                Format::recordId($item->id),
                (string) $result['previousType'],
                $matched,
            ));

            return;
        }
        $result = $this->modifyRecordById('type', $id, function (Record $current) use ($matched): ?Record {
            return $current->type === $matched
                ? null
                : $current->withType($matched, $this->clock->nowMinute(), 'type');
        });
        if ($result !== null) {
            [$previous, $updated] = $result;
            $this->io->err(sprintf(
                "Changed type of %s %s from %s to %s\n",
                $updated->issueId,
                Format::recordId($updated->id),
                $previous->type,
                $matched,
            ));
        }
    }

    private function cmdSwitch(?string $newType): void
    {
        $matched = Resolver::resolveType('switch', $newType, null, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
        $this->store->transaction(function () use ($matched): void {
            $items = $this->store->load();
            $last = $items === [] ? null : $items[count($items) - 1];
            if ($last === null || !$last->isOpen()) {
                throw new RuntimeException('switch: no open tracking entry');
            }
            $trigger = 'switch';
            $now = $this->clock->nowMinute();
            $next = new Record(
                id: $this->store->nextId(),
                issueId: $last->issueId,
                type: $matched,
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, $trigger),
            );
            $result = $this->store->track($next, $trigger);
            if (!$result['started']) {
                return;
            }
            $stopped = $result['stopped'];
            if ($stopped !== null && $stopped->endedAt !== null) {
                $this->io->err(sprintf(
                    "Stopped %s as %s after %s\n",
                    $this->actionRef($stopped->issueId, $stopped->id),
                    Format::typeInline($stopped->type),
                    Format::durationInline($stopped->startedAt, $stopped->endedAt),
                ));
            }
            $this->io->err(sprintf("Tracking %s as %s\n", $this->actionRef($next->issueId, $next->id), Format::typeInline($matched)));
        });
    }

    private function cmdEnd(?string $note): void
    {
        $resolved = $note === '' ? null : $note;
        $result = $this->store->endOpen($this->clock->nowMinute(), 'end', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('end: no open tracking entry');
        }
        $this->announceStopped($item);
    }

    private function cmdDone(?string $note): void
    {
        $resolved = $note === '' ? null : $note;
        $this->store->transaction(function () use ($resolved): void {
            $result = $this->store->endOpen($this->clock->nowMinute(), 'done', $resolved);
            $item = $result['item'];
            if ($item === null) {
                throw new RuntimeException('done: no open tracking entry');
            }
            $this->announceStopped($item);

            $items = $this->store->load();
            $target = null;
            for ($i = count($items) - 2; $i >= 0; $i--) {
                if ($items[$i]->status === 'paused') {
                    $target = $items[$i];
                    break;
                }
            }
            if ($target !== null) {
                $this->resumeFrom($target, 'done');
            }
        });
    }

    private function announceStopped(Record $item): void
    {
        $endedAt = $item->endedAt ?? '';
        $this->io->err(sprintf(
            "Stopped %s after %s\n",
            $this->actionRef($item->issueId, $item->id),
            Format::durationInline($item->startedAt, $endedAt),
        ));
        if ($item->note !== '') {
            $this->io->err(sprintf("Note: %s\n", Ansi::lblack('"' . $item->note . '"')));
        }
    }

    private function resumeFrom(Record $target, string $trigger): void
    {
        $now = $this->clock->nowMinute();
        $next = new Record(
            id: $this->store->nextId(),
            issueId: $target->issueId,
            type: $target->type,
            startedAt: $now,
            endedAt: null,
            log: Record::logCreated($now, $trigger),
        );
        $this->store->track($next, $trigger);
        $this->io->err(sprintf("Resumed %s as %s\n", $this->actionRef($next->issueId, $next->id), Format::typeInline($next->type)));
    }

    private function cmdPause(?string $note): void
    {
        $this->store->transaction(function () use ($note): void {
            $items = $this->store->load();
            $last = $items === [] ? null : $items[count($items) - 1];
            if ($last === null || !$last->isOpen()) {
                throw new RuntimeException('pause: no open tracking entry');
            }

            $now = $this->clock->nowMinute();
            $break = new Record(
                id: $this->store->nextId(),
                issueId: '',
                type: '',
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, 'pause'),
                note: $note ?? '',
                status: 'untracked',
            );
            $result = $this->store->track($break, 'pause', pauseClosed: true);
            if (!$result['started']) {
                return;
            }
            $stopped = $result['stopped'];
            if ($stopped !== null && $stopped->endedAt !== null) {
                $this->io->err(sprintf(
                    "Paused %s after %s\n",
                    $this->actionRef($stopped->issueId, $stopped->id),
                    Format::durationInline($stopped->startedAt, $stopped->endedAt),
                ));
            }
            if ($break->note !== '') {
                $this->io->err(sprintf("Note: %s\n", Ansi::lblack('"' . $break->note . '"')));
            }
        });
    }

    private function cmdResume(): void
    {
        $this->store->transaction(function (): void {
            $items = $this->store->load();
            if ($items === []) {
                throw new RuntimeException('resume: no entry to resume');
            }
            $last = $items[count($items) - 1];
            if ($last->isOpen() && $last->status !== 'untracked') {
                throw new RuntimeException('resume: an entry is already open');
            }
            $target = null;
            for ($i = count($items) - 1; $i >= 0; $i--) {
                $r = $items[$i];
                if ($r->status === 'paused') {
                    $target = $r;
                    break;
                }
            }
            if ($target === null) {
                for ($i = count($items) - 1; $i >= 0; $i--) {
                    $r = $items[$i];
                    if (!$r->isOpen() && $r->status !== 'untracked') {
                        $target = $r;
                        break;
                    }
                }
            }
            if ($target === null) {
                throw new RuntimeException('resume: no entry to resume');
            }
            $this->resumeFrom($target, 'resume');
        });
    }

    private function cmdSkip(?string $span): void
    {
        $spanMin = Resolver::parseSpan('skip', $span);

        $this->store->transaction(function () use ($spanMin): void {
            $items = $this->store->load();
            $last = $items === [] ? null : $items[count($items) - 1];
            if ($last === null || !$last->isOpen()) {
                throw new RuntimeException('skip: no open tracking entry');
            }

            $nowDt = $this->clock->now();
            $endDt = $nowDt->modify("-{$spanMin} minutes");
            try {
                $startDt = new DateTimeImmutable($last->startedAt);
            } catch (Exception) {
                throw new RuntimeException("skip: invalid existing time '{$last->startedAt}'");
            }
            if ($endDt <= $startDt) {
                throw new RuntimeException("skip: span too large — would end at or before the open entrie's start ({$last->startedAt})");
            }

            $now = $nowDt->format('Y-m-d H:i');
            $endedAt = $endDt->format('Y-m-d H:i');

            $closed = $last->withEnd($endedAt, 'skip');
            $next = new Record(
                id: $this->store->nextId(),
                issueId: $last->issueId,
                type: $last->type,
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, 'skip'),
            );

            $items[count($items) - 1] = $closed;
            $items[] = $next;
            $this->store->save($items);

            $this->io->err(sprintf(
                "Skipped %s of %s: ended at %s, restarted at %s\n",
                Format::durationInline($endedAt, $now),
                $this->actionRef($last->issueId, $last->id),
                $endedAt,
                $now,
            ));
        });
    }

    private function cmdGrab(?string $issue, ?string $span, ?string $type): void
    {
        $issueId = $this->resolveIssueArg('grab', $issue);
        $spanMin = Resolver::parseSpan('grab', $span);
        $resolvedType = Resolver::resolveType('grab', $type, $this->config->defaultTrackType, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);

        $this->store->transaction(function () use ($issueId, $spanMin, $resolvedType): void {
            $items = $this->store->load();
            $last = $items === [] ? null : $items[count($items) - 1];
            if ($last === null || !$last->isOpen()) {
                throw new RuntimeException('grab: no open tracking entry');
            }

            $nowDt = $this->clock->now();
            $splitDt = $nowDt->modify("-{$spanMin} minutes");
            try {
                $startDt = new DateTimeImmutable($last->startedAt);
            } catch (Exception) {
                throw new RuntimeException("grab: invalid existing time '{$last->startedAt}'");
            }
            if ($splitDt <= $startDt) {
                throw new RuntimeException("grab: span too large — would split at or before the open entrie's start ({$last->startedAt})");
            }

            $now = $nowDt->format('Y-m-d H:i');
            $splitAt = $splitDt->format('Y-m-d H:i');

            $closed = $last->withEnd($splitAt, 'grab');
            $grabbedLog = Record::logCreated($splitAt, 'grab') . ' | ' . Record::logClosed($now, 'grab');
            $grabbed = new Record(
                id: $this->store->nextId(),
                issueId: $issueId,
                type: $resolvedType,
                startedAt: $splitAt,
                endedAt: $now,
                log: $grabbedLog,
            );
            $continuation = new Record(
                id: $this->store->nextId(),
                issueId: $last->issueId,
                type: $last->type,
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, 'grab'),
            );

            $items[count($items) - 1] = $closed;
            $items[] = $grabbed;
            $items[] = $continuation;
            $this->store->save($items);

            $this->io->err(sprintf(
                "Grabbed %s of %s as %s from %s between %s and %s\n",
                Format::durationInline($splitAt, $now),
                $this->actionRef($issueId, $grabbed->id),
                Format::typeInline($resolvedType),
                $this->actionRef($last->issueId, $last->id),
                substr($splitAt, 11),
                substr($now, 11),
            ));
        });
    }

    private function cmdPut(?string $issue, ?string $span, ?string $type): void
    {
        $issueId = $this->resolveIssueArg('put', $issue);
        $spanMin = Resolver::parseSpan('put', $span);
        $resolvedType = Resolver::resolveType('put', $type, $this->config->defaultTrackType, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);

        $this->store->transaction(function () use ($issueId, $spanMin, $resolvedType): void {
            $midnight = $this->clock->now()->setTime(0, 0);
            $endDt = $midnight->modify("+{$spanMin} minutes");
            $start = $midnight->format('Y-m-d H:i');
            $end = $endDt->format('Y-m-d H:i');
            $log = Record::logCreated($start, 'put') . ' | ' . Record::logClosed($end, 'put');
            $record = new Record(
                id: $this->store->nextId(),
                issueId: $issueId,
                type: $resolvedType,
                startedAt: $start,
                endedAt: $end,
                log: $log,
            );
            $this->store->appendClosed($record);
            $this->io->err(sprintf(
                "Put %s as %s (%s)\n",
                $this->actionRef($issueId, $record->id),
                Format::typeInline($resolvedType),
                Format::durationInline($start, $end),
            ));
        });
    }

    private function cmdNote(?string $rest): void
    {
        [$id, $note] = Resolver::peelRecordId('note', $rest);
        if ($note === null || $note === '') {
            throw new RuntimeException('note: missing <text>');
        }
        if ($id === null) {
            $result = $this->store->noteLast($note, $this->clock->nowMinute(), 'note');
            $item = $result['item'];
            if ($item === null) {
                throw new RuntimeException('note: no entry to add note to');
            }
            if (!$result['changed']) {
                return;
            }
            $where = $item->isOpen() ? 'active' : 'last closed';
            $this->io->err(sprintf("Note on %s (%s): %s\n", $this->actionRef($item->issueId, $item->id), $where, Ansi::lblack('"' . $item->note . '"')));

            return;
        }
        $result = $this->modifyRecordById('note', $id, function (Record $current) use ($note): ?Record {
            $merged = $current->note === '' ? $note : $current->note . ' | ' . $note;

            return $merged === $current->note
                ? null
                : $current->withNote($merged, $this->clock->nowMinute(), 'note');
        });
        if ($result !== null) {
            [, $updated] = $result;
            $where = $updated->isOpen() ? 'active' : 'closed';
            $this->io->err(sprintf("Note on %s (%s): %s\n", $this->actionRef($updated->issueId, $updated->id), $where, Ansi::lblack('"' . $updated->note . '"')));
        }
    }

    private function cmdEdit(?string $idArg): void
    {
        if ($idArg === null || $idArg === '') {
            throw new RuntimeException('edit: missing <id>');
        }
        if (!ctype_digit($idArg)) {
            throw new RuntimeException("edit: invalid id '{$idArg}' (expected positive integer)");
        }
        $id = (int) $idArg;

        $target = $this->store->transaction(function () use ($id): ?Record {
            $items = $this->store->load();
            $index = self::findRecordIndex($items, $id);

            return $index === null ? null : $items[$index];
        });
        if ($target === null) {
            throw new RuntimeException("edit: entry #{$id} not found");
        }

        $original = Neon::encode([
            'id' => $target->id,
            'issueId' => $target->issueId,
            'type' => $target->type,
            'status' => $target->status,
            'startedAt' => $target->startedAt,
            'endedAt' => $target->endedAt,
            'note' => $target->note,
            'log' => $target->log,
        ], Neon::BLOCK);

        $tempPath = sys_get_temp_dir() . '/timeshit-edit-' . $id . '-' . uniqid() . '.neon';
        if (file_put_contents($tempPath, $original) === false) {
            throw new RuntimeException("edit: failed to write temp file {$tempPath}");
        }

        try {
            $cmd = $this->config->editor . ' ' . escapeshellarg($tempPath);
            $proc = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
            if (!is_resource($proc)) {
                throw new RuntimeException("edit: failed to launch editor '{$this->config->editor}'");
            }
            $exitCode = proc_close($proc);
            if ($exitCode !== 0) {
                throw new RuntimeException("edit: editor '{$this->config->editor}' exited with code {$exitCode}");
            }
            $updatedRaw = file_get_contents($tempPath);
            if ($updatedRaw === false) {
                throw new RuntimeException("edit: failed to read temp file {$tempPath}");
            }
        } finally {
            @unlink($tempPath);
        }

        if ($updatedRaw === $original) {
            $this->io->err("No changes.\n");

            return;
        }

        try {
            $decoded = Neon::decode($updatedRaw);
        } catch (Throwable $e) {
            throw new RuntimeException("edit: failed to parse temp file as NEON: " . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('edit: temp file is not a NEON map');
        }
        $newId = $decoded['id'] ?? null;
        if (!is_int($newId)) {
            throw new RuntimeException("edit: 'id' field must be an integer");
        }
        if ($newId !== $id) {
            throw new RuntimeException("edit: 'id' field must remain {$id} (got {$newId})");
        }

        $parsed = Record::fromArray($decoded);
        if ($parsed->endedAt !== null) {
            try {
                $sDt = new DateTimeImmutable($parsed->startedAt);
                $eDt = new DateTimeImmutable($parsed->endedAt);
            } catch (Exception) {
                throw new RuntimeException("edit: invalid timestamps ({$parsed->startedAt} / {$parsed->endedAt})");
            }
            if ($sDt >= $eDt) {
                throw new RuntimeException("edit: would result in non-positive duration ({$parsed->startedAt} → {$parsed->endedAt})");
            }
        }

        $this->store->transaction(function () use ($target, $parsed): void {
            $items = $this->store->load();
            $index = self::findRecordIndex($items, $target->id);
            if ($index === null) {
                throw new RuntimeException("edit: entry #{$target->id} disappeared during edit");
            }
            $current = $items[$index];

            $logUserEdited = $parsed->log !== $current->log;
            $finalLog = $parsed->log;
            $changedFields = [];
            if (!$logUserEdited) {
                $now = $this->clock->nowMinute();
                $fields = [
                    'issueId' => [$current->issueId, $parsed->issueId],
                    'type' => [$current->type, $parsed->type],
                    'status' => [$current->status, $parsed->status],
                    'startedAt' => [$current->startedAt, $parsed->startedAt],
                    'endedAt' => [$current->endedAt ?? '(open)', $parsed->endedAt ?? '(open)'],
                    'note' => [$current->note, $parsed->note],
                ];
                $entries = [];
                foreach ($fields as $field => [$old, $new]) {
                    if ($old !== $new) {
                        $entries[] = Record::logEdit($field, $old, $new, $now, 'edit');
                        $changedFields[] = $field;
                    }
                }
                if ($entries !== []) {
                    $appended = implode(' | ', $entries);
                    $finalLog = $current->log === '' ? $appended : $current->log . ' | ' . $appended;
                }
            }

            $final = new Record(
                id: $current->id,
                issueId: $parsed->issueId,
                type: $parsed->type,
                startedAt: $parsed->startedAt,
                endedAt: $parsed->endedAt,
                log: $finalLog,
                note: $parsed->note,
                status: $parsed->status,
            );
            $items[$index] = $final;
            $this->store->save(array_values($items));

            $summary = $logUserEdited
                ? 'log rewritten by user'
                : ($changedFields === [] ? 'no field changes' : 'changed: ' . implode(', ', $changedFields));
            $this->io->err(sprintf(
                "Edited %s (%s)\n",
                $this->actionRef($final->issueId, $final->id),
                $summary,
            ));
        });
    }

    private function cmdDelete(?string $idArg): void
    {
        if ($idArg === null || $idArg === '') {
            throw new RuntimeException('delete: missing <id>');
        }
        if (!ctype_digit($idArg)) {
            throw new RuntimeException("delete: invalid id '{$idArg}' (expected positive integer)");
        }
        $id = (int) $idArg;

        $this->store->transaction(function () use ($id): void {
            $items = $this->store->load();
            $index = self::findRecordIndex($items, $id);
            if ($index === null) {
                throw new RuntimeException("delete: entry #{$id} not found");
            }
            $target = $items[$index];

            $baseUrl = rtrim($this->config->youtrackBaseUrl, '/');
            $titleByIssueId = $this->issueData->titles();
            $this->io->err($this->statusLine($target, $baseUrl, $titleByIssueId) . "\n\n");

            if (!$this->confirm('Delete this entry?')) {
                $this->io->err("Cancelled.\n");

                return;
            }

            unset($items[$index]);
            $this->store->save(array_values($items));
            $this->io->err(sprintf("Deleted %s\n", $this->actionRef($target->issueId, $target->id)));
        });
    }

    private function cmdTrack(?string $issue, ?string $type): void
    {
        $issueId = $this->resolveIssueArg('track', $issue);
        $resolvedType = Resolver::resolveType('track', $type, $this->config->defaultTrackType, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
        $this->startRecord($issueId, $resolvedType, 'track');
    }

    private function cmdInterrupt(?string $issue, ?string $type): void
    {
        $issueId = $this->resolveIssueArg('interrupt', $issue);
        $resolvedType = Resolver::resolveType('interrupt', $type, $this->config->defaultTrackType, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
        $this->startRecord($issueId, $resolvedType, 'interrupt', pauseClosed: true);
    }

    private function cmdMeeting(?string $note): void
    {
        $resolved = $note === '' ? null : $note;
        $this->startRecord(
            $this->config->defaultMeetingIssue,
            $this->config->defaultMeetingType,
            'meeting',
            pauseClosed: true,
            note: $resolved,
        );
    }

    private function cmdMail(?string $note): void
    {
        $resolved = $note === '' ? null : $note;
        $this->startRecord(
            $this->config->defaultMailIssue,
            $this->config->defaultMailType,
            'mail',
            pauseClosed: true,
            note: $resolved,
        );
    }

    private function cmdReview(?string $issue): void
    {
        $issueId = $this->resolveIssueArg('review', $issue);
        $this->startRecord(
            $issueId,
            $this->config->defaultReviewType,
            'review',
            pauseClosed: false,
        );
    }

    private function cmdTest(?string $issue): void
    {
        $issueId = $this->resolveIssueArg('test', $issue);
        $this->startRecord(
            $issueId,
            $this->config->defaultTestType,
            'test',
            pauseClosed: false,
        );
    }

    private function cmdImplement(?string $issue): void
    {
        $issueId = $this->resolveIssueArg('implement', $issue);
        $this->startRecord(
            $issueId,
            $this->config->defaultImplementType,
            'implement',
            pauseClosed: false,
        );
    }

    private function cmdAnalyse(?string $issue): void
    {
        $issueId = $this->resolveIssueArg('analyse', $issue);
        $this->startRecord(
            $issueId,
            $this->config->defaultAnalyseType,
            'analyse',
            pauseClosed: false,
        );
    }

    private function cmdDesign(?string $issue): void
    {
        $issueId = $this->resolveIssueArg('design', $issue);
        $this->startRecord(
            $issueId,
            $this->config->defaultDesignType,
            'design',
            pauseClosed: false,
        );
    }

    private function cmdDay(?string $issue, ?string $date, ?string $type): void
    {
        $issueId = $this->resolveIssueArg('day', $issue);
        $when = Resolver::resolveDate($date, 'day', $this->clock->now()->setTime(0, 0));
        $resolvedType = Resolver::resolveType('day', $type, $this->config->defaultDayType, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
        $dayKey = $when->format('Y-m-d');
        $this->store->transaction(function () use ($issueId, $when, $resolvedType, $dayKey): void {
            foreach ($this->store->load() as $existing) {
                if ($existing->status !== 'day') {
                    continue;
                }
                if ((new DateTimeImmutable($existing->startedAt))->format('Y-m-d') === $dayKey) {
                    throw new RuntimeException(
                        "day: a full-day entry already exists on {$dayKey} ({$existing->issueId}, {$existing->type})",
                    );
                }
            }
            $start = $when->setTime(9, 0)->format('Y-m-d H:i');
            $end = $when->setTime(17, 0)->format('Y-m-d H:i');
            $log = Record::logCreated($start, 'day') . ' | ' . Record::logClosed($end, 'day');
            $record = new Record(
                id: $this->store->nextId(),
                issueId: $issueId,
                type: $resolvedType,
                startedAt: $start,
                endedAt: $end,
                log: $log,
                status: 'day',
            );
            $this->store->appendClosed($record);
            $this->io->err(sprintf(
                "Logged %s %s for %s as %s (8h)\n",
                $issueId,
                Format::recordId($record->id),
                $dayKey,
                $resolvedType,
            ));
        });
    }

    private function cmdVacation(?string $from, ?string $to): void
    {
        if ($from === null || $from === '') {
            throw new RuntimeException('vacation: missing <from>');
        }
        if ($to === null || $to === '') {
            throw new RuntimeException('vacation: missing <to>');
        }
        $today = $this->clock->now()->setTime(0, 0);
        $fromDt = Resolver::resolveDate($from, 'vacation', $today)->setTime(0, 0);
        $toDt = Resolver::resolveDate($to, 'vacation', $today)->setTime(0, 0);
        if ($fromDt > $toDt) {
            throw new RuntimeException(
                "vacation: <from> ({$fromDt->format('Y-m-d')}) is after <to> ({$toDt->format('Y-m-d')})",
            );
        }
        $issueId = $this->config->defaultOutOfOfficeIssue;
        $type = $this->config->defaultOutOfOfficeType;

        $workingDays = [];
        for ($cursor = $fromDt; $cursor <= $toDt; $cursor = $cursor->modify('+1 day')) {
            $weekday = (int) $cursor->format('N');
            if ($weekday >= 6) {
                continue;
            }
            $workingDays[] = $cursor;
        }
        if ($workingDays === []) {
            throw new RuntimeException(
                "vacation: no working days between {$fromDt->format('Y-m-d')} and {$toDt->format('Y-m-d')}",
            );
        }

        $this->store->transaction(function () use ($workingDays, $issueId, $type): void {
            $existingDays = [];
            foreach ($this->store->load() as $existing) {
                if ($existing->status !== 'day') {
                    continue;
                }
                $existingDays[(new DateTimeImmutable($existing->startedAt))->format('Y-m-d')] = $existing;
            }
            foreach ($workingDays as $day) {
                $key = $day->format('Y-m-d');
                if (isset($existingDays[$key])) {
                    $clash = $existingDays[$key];
                    throw new RuntimeException(
                        "vacation: a full-day entry already exists on {$key} ({$clash->issueId}, {$clash->type})",
                    );
                }
            }
            foreach ($workingDays as $day) {
                $start = $day->setTime(9, 0)->format('Y-m-d H:i');
                $end = $day->setTime(17, 0)->format('Y-m-d H:i');
                $log = Record::logCreated($start, 'vacation') . ' | ' . Record::logClosed($end, 'vacation');
                $record = new Record(
                    id: $this->store->nextId(),
                    issueId: $issueId,
                    type: $type,
                    startedAt: $start,
                    endedAt: $end,
                    log: $log,
                    status: 'day',
                );
                $this->store->appendClosed($record);
                $this->io->err(sprintf(
                    "Logged %s %s for %s as %s (8h)\n",
                    $issueId,
                    Format::recordId($record->id),
                    $day->format('Y-m-d'),
                    $type,
                ));
            }
        });
    }

    private function cmdCheckout(?string $branch, ?string $repo): void
    {
        if ($branch === null || $branch === '') {
            throw new RuntimeException('checkout: missing <branch>');
        }
        if ($repo === null || $repo === '') {
            throw new RuntimeException('checkout: missing <repo>');
        }
        $issueId = Resolver::extractIssueId($branch, $this->config->defaultIssuePrefix);
        if (Resolver::isStandardIssueId($issueId)) {
            $this->issueData->ensureIssue($issueId);
        }
        $this->startRecord($issueId, null, 'checkout');
    }

    private function resolveIssueArg(string $cmd, ?string $issue): string
    {
        $issueId = Resolver::requireIssueId($cmd, $issue, $this->config->defaultIssuePrefix);
        if (!Resolver::isStandardIssueId($issueId)) {
            $this->io->err(Ansi::lyellow("Warning: '{$issueId}' has unusual issue id format (expected ABC-123)") . "\n");

            return $issueId;
        }
        $this->issueData->ensureIssue($issueId);

        return $issueId;
    }

    /**
     * @param ?bool $pauseClosed null = use type-based auto-detection (default for `track` / `checkout`);
     *                           true = always pause the closed record (`interrupt` / `meeting` / `mail`);
     *                           false = never pause, plain track-replace (`review` / `test`).
     */
    private function startRecord(
        string $issueId,
        ?string $type,
        string $trigger,
        ?bool $pauseClosed = null,
        ?string $note = null,
    ): void {
        $resolvedType = $type === null || $type === '' ? $this->config->defaultTrackType : $type;

        $this->store->transaction(function () use ($issueId, $resolvedType, $trigger, $pauseClosed, $note): void {
            $items = $this->store->load();
            $last = $items === [] ? null : $items[count($items) - 1];
            $effectivePause = false;
            if ($last !== null && $last->isOpen()) {
                if ($pauseClosed !== null) {
                    $effectivePause = $pauseClosed;
                } elseif ($last->type === $this->config->defaultTrackType
                    && in_array($resolvedType, $this->config->interruptionTypes, true)
                ) {
                    $effectivePause = true;
                }
            }

            $now = $this->clock->nowMinute();
            $next = new Record(
                id: $this->store->nextId(),
                issueId: $issueId,
                type: $resolvedType,
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, $trigger),
                note: $note ?? '',
            );
            $result = $this->store->track($next, $trigger, $effectivePause);
            if (!$result['started']) {
                return;
            }
            $stopped = $result['stopped'];
            if ($stopped !== null && $stopped->endedAt !== null) {
                $this->io->err(sprintf(
                    "Stopped %s as %s after %s\n",
                    $this->actionRef($stopped->issueId, $stopped->id),
                    Format::typeInline($stopped->type),
                    Format::durationInline($stopped->startedAt, $stopped->endedAt),
                ));
            }
            $this->io->err(sprintf("Tracking %s as %s\n", $this->actionRef($next->issueId, $next->id), Format::typeInline($resolvedType)));
            if ($next->note !== '') {
                $this->io->err(sprintf("Note: %s\n", Ansi::lblack('"' . $next->note . '"')));
            }
        });
    }

    private function cmdAt(?string $rest): void
    {
        [$id, $arg] = Resolver::peelRecordId('at', $rest);
        $this->modifyTimes('at', 'set', $arg, $id);
    }

    private function cmdBefore(?string $rest): void
    {
        [$id, $arg] = Resolver::peelRecordId('before', $rest);
        $this->modifyTimes('before', 'sub', $arg, $id);
    }

    private function cmdAfter(?string $rest): void
    {
        [$id, $arg] = Resolver::peelRecordId('after', $rest);
        $this->modifyTimes('after', 'add', $arg, $id);
    }

    private function modifyTimes(string $cmd, string $mode, ?string $arg, ?int $targetId): void
    {
        $this->store->transaction(function () use ($cmd, $mode, $arg, $targetId): void {
            $this->modifyTimesLocked($cmd, $mode, $arg, $targetId);
        });
    }

    private function modifyTimesLocked(string $cmd, string $mode, ?string $arg, ?int $targetId): void
    {
        $items = $this->store->load();

        $targetIndex = null;
        if ($targetId !== null) {
            $targetIndex = self::findRecordIndex($items, $targetId);
            if ($targetIndex === null) {
                throw new RuntimeException("{$cmd}: entry #{$targetId} not found");
            }
            if ($items[$targetIndex]->status === 'day') {
                throw new RuntimeException("{$cmd}: refusing to edit day entry #{$targetId}");
            }
        } else {
            for ($i = count($items) - 1; $i >= 0; $i--) {
                if ($items[$i]->status === 'day') {
                    continue;
                }
                $targetIndex = $i;
                break;
            }
            if ($targetIndex === null) {
                throw new RuntimeException("{$cmd}: no entry to modify");
            }
        }

        $target = $items[$targetIndex];
        $isOpen = $target->isOpen();

        if ($cmd === 'after' && $isOpen) {
            throw new RuntimeException('after: last entry is open (use before/at to move its start)');
        }

        if ($isOpen) {
            $existing = $target->startedAt;
        } else {
            $existing = $target->endedAt;
            if ($existing === null) {
                throw new RuntimeException("{$cmd}: closed entry has no end time");
            }
        }

        if ($mode === 'set') {
            $newDt = Resolver::resolveTime($cmd, $arg, $existing);
        } else {
            $spanMin = Resolver::parseSpan($cmd, $arg);
            try {
                $existingDt = new DateTimeImmutable($existing);
            } catch (Exception) {
                throw new RuntimeException("{$cmd}: invalid existing time '{$existing}'");
            }
            $sign = $mode === 'sub' ? '-' : '+';
            $newDt = $existingDt->modify("{$sign}{$spanMin} minutes");
        }
        $newValue = $newDt->format('Y-m-d H:i');

        if ($newValue === $existing) {
            $this->io->err("No change.\n");

            return;
        }

        $modifiedAt = $this->clock->nowMinute();
        $newItems = $items;
        $adjustedPrevIndex = null;

        if ($isOpen) {
            $newItems[$targetIndex] = $target->withStartedAt($newValue, $modifiedAt, $cmd);
            if ($targetIndex > 0) {
                $prev = $items[$targetIndex - 1];
                if ($prev->status !== 'day' && $prev->endedAt === $target->startedAt) {
                    $newItems[$targetIndex - 1] = $prev->withEndedAt($newValue, $modifiedAt, $cmd);
                    $adjustedPrevIndex = $targetIndex - 1;
                }
            }
        } else {
            $newItems[$targetIndex] = $target->withEndedAt($newValue, $modifiedAt, $cmd);
        }

        $updated = $newItems[$targetIndex];
        if ($updated->endedAt !== null) {
            try {
                $sDt = new DateTimeImmutable($updated->startedAt);
                $eDt = new DateTimeImmutable($updated->endedAt);
            } catch (Exception) {
                throw new RuntimeException("{$cmd}: invalid resulting timestamps");
            }
            if ($sDt >= $eDt) {
                throw new RuntimeException("{$cmd}: would result in non-positive duration ({$updated->startedAt} → {$updated->endedAt})");
            }
        }
        if ($adjustedPrevIndex !== null) {
            $adjusted = $newItems[$adjustedPrevIndex];
            if ($adjusted->endedAt !== null) {
                try {
                    $sDt = new DateTimeImmutable($adjusted->startedAt);
                    $eDt = new DateTimeImmutable($adjusted->endedAt);
                } catch (Exception) {
                    throw new RuntimeException("{$cmd}: invalid resulting timestamps on previous entry");
                }
                if ($sDt >= $eDt) {
                    throw new RuntimeException("{$cmd}: would result in non-positive duration on previous entry ({$adjusted->startedAt} → {$adjusted->endedAt})");
                }
            }
        }

        $firstShownIndex = $adjustedPrevIndex ?? $targetIndex;
        $this->io->err(Ansi::lwhite('Old:') . "\n");
        for ($i = $firstShownIndex; $i <= $targetIndex; $i++) {
            $this->io->err($this->recordLine($items[$i]) . "\n");
        }
        $this->io->err("\n" . Ansi::lwhite('New:') . "\n");
        for ($i = $firstShownIndex; $i <= $targetIndex; $i++) {
            $this->io->err($this->recordLine($newItems[$i]) . "\n");
        }
        $this->io->err("\n");

        if (!$this->confirm('Apply?')) {
            $this->io->err("Cancelled.\n");

            return;
        }

        $this->store->save(array_values($newItems));
        $this->io->err("Saved.\n");
    }

    private function recordLine(Record $r): string
    {
        $start = substr($r->startedAt, 11);
        if ($r->endedAt === null) {
            $now = $this->clock->nowMinute();
            $minutes = max(0, intdiv((new DateTimeImmutable($now))->getTimestamp() - (new DateTimeImmutable($r->startedAt))->getTimestamp(), 60));
            $duration = Format::spent($minutes, null, false) . Ansi::lblack('so far');
            $timeRange = $start . ' ' . Ansi::lblack('→') . ' ' . Ansi::lgreen('  …  ');
        } else {
            $minutes = max(0, intdiv((new DateTimeImmutable($r->endedAt))->getTimestamp() - (new DateTimeImmutable($r->startedAt))->getTimestamp(), 60));
            $duration = Format::spent($minutes, null, false);
            $timeRange = $start . Ansi::lblack('–') . substr($r->endedAt, 11);
        }
        $url = rtrim($this->config->youtrackBaseUrl, '/') . '/issue/' . $r->issueId;

        return sprintf(
            '  %s %s  %s  %s  %s',
            Ansi::link($url, sprintf('%-9s', $r->issueId)),
            Format::recordId($r->id),
            Format::type($r->type),
            $timeRange,
            Ansi::lblack('(') . $duration . Ansi::lblack(')'),
        );
    }

    private function confirm(string $question): bool
    {
        $this->io->err("{$question} [y/N]: ");
        $line = $this->io->readLine();
        if ($line === null) {
            return false;
        }

        return in_array(strtolower(trim($line)), ['y', 'yes'], true);
    }

    private function cmdPush(?string $dateArg): void
    {
        $cutoffKey = Resolver::resolveDate($dateArg ?? 'yesterday', 'push', $this->clock->now()->setTime(0, 0))->format('Y-m-d');

        $records = $this->store->load();
        /** @var list<Record> $eligible */
        $eligible = [];
        foreach ($records as $r) {
            if ($r->isOpen()) {
                continue;
            }
            if ($r->status === 'synced' || $r->status === 'untracked') {
                continue;
            }
            if ($r->issueId === '') {
                continue;
            }
            $date = substr($r->startedAt, 0, 10);
            if ($date > $cutoffKey) {
                continue;
            }
            $eligible[] = $r;
        }
        if ($eligible === []) {
            $this->io->err("Nothing to push (cutoff: {$cutoffKey}).\n");

            return;
        }

        /** @var array<string, array{date: string, issueId: string, type: string, minutes: int, ids: list<int>, notes: list<string>}> $groups */
        $groups = [];
        foreach ($eligible as $r) {
            $date = substr($r->startedAt, 0, 10);
            $key = $date . '|' . $r->issueId . '|' . $r->type;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $date,
                    'issueId' => $r->issueId,
                    'type' => $r->type,
                    'minutes' => 0,
                    'ids' => [],
                    'notes' => [],
                ];
            }
            $groups[$key]['minutes'] += self::recordMinutes($r);
            $groups[$key]['ids'][] = $r->id;
            if ($r->note !== '' && !in_array($r->note, $groups[$key]['notes'], true)) {
                $groups[$key]['notes'][] = $r->note;
            }
        }
        ksort($groups);

        $typeNameToId = [];
        foreach ($this->types->types() as $t) {
            $typeNameToId[$t->name] = $t->id;
        }

        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($groups as $g) {
            $dayTotal[$g['date']] = ($dayTotal[$g['date']] ?? 0) + $g['minutes'];
        }

        $tz = new DateTimeZone($this->config->timezone);
        $succeeded = 0;
        $failed = 0;
        $currentDate = '';
        /** @var array<string, true> $syncedDays */
        $syncedDays = [];

        foreach ($groups as $g) {
            if ($g['date'] !== $currentDate) {
                $this->printPushDateHeader($g['date'], $dayTotal[$g['date']]);
                $currentDate = $g['date'];
            }
            if ($g['minutes'] <= 0) {
                $this->printPushSkipped($g);
                continue;
            }
            $typeId = $typeNameToId[$g['type']] ?? null;
            if ($typeId === null) {
                $reason = "unknown work-item type '{$g['type']}'";
                $this->store->markFailed($g['ids'], $reason, $this->clock->nowMinute(), 'push');
                $this->printPushFailure($g, $reason);
                $failed++;
                continue;
            }
            try {
                $dateMs = (new DateTimeImmutable($g['date'], $tz))->getTimestamp() * 1000;
            } catch (Exception) {
                $reason = "invalid date '{$g['date']}'";
                $this->store->markFailed($g['ids'], $reason, $this->clock->nowMinute(), 'push');
                $this->printPushFailure($g, $reason);
                $failed++;
                continue;
            }
            $text = implode(' | ', $g['notes']);
            try {
                $workItemId = $this->pusher->push($g['issueId'], $dateMs, $g['minutes'], $typeId, $text);
            } catch (RuntimeException $e) {
                $this->store->markFailed($g['ids'], $e->getMessage(), $this->clock->nowMinute(), 'push');
                $this->printPushFailure($g, $e->getMessage());
                $failed++;
                continue;
            }
            $this->store->archive($g['ids'], $workItemId, $this->clock->nowMinute(), 'push');
            $this->printPushSuccess($g, $workItemId);
            $succeeded++;
            $syncedDays[$g['date']] = true;
        }

        if ($syncedDays !== []) {
            $untrackedIds = [];
            foreach ($this->store->load() as $r) {
                if ($r->status !== 'untracked') {
                    continue;
                }
                if (isset($syncedDays[substr($r->startedAt, 0, 10)])) {
                    $untrackedIds[] = $r->id;
                }
            }
            if ($untrackedIds !== []) {
                $this->store->archiveUntracked($untrackedIds, $this->clock->nowMinute(), 'push');
            }
        }

        $this->io->err(sprintf("\nPushed: %d, failed: %d (cutoff %s)\n", $succeeded, $failed, $cutoffKey));

        if ($succeeded > 0) {
            $this->issueData->refresh();
            $this->types->refresh();
        }
    }

    private static function recordMinutes(Record $r): int
    {
        if ($r->endedAt === null) {
            return 0;
        }
        try {
            $s = new DateTimeImmutable($r->startedAt);
            $e = new DateTimeImmutable($r->endedAt);
        } catch (Exception) {
            return 0;
        }

        return max(0, intdiv($e->getTimestamp() - $s->getTimestamp(), 60));
    }

    private function printPushDateHeader(string $date, int $minutes): void
    {
        $dt = new DateTimeImmutable($date);
        $isWeekend = (int) $dt->format('N') >= 6;
        $dayColor = match (true) {
            $isWeekend => Ansi::yellow(...),
            $minutes >= 8 * 60 => Ansi::lgreen(...),
            default => Ansi::red(...),
        };
        $dayLabel = $dt->format('l j.n.');
        $this->io->err('  ' . $dayColor(sprintf('%-16s', $dayLabel)) . '  ' . Format::spent($minutes, $dayColor) . "\n");
    }

    /** @param array{date: string, issueId: string, type: string, minutes: int, ids: list<int>, notes: list<string>} $g */
    private function printPushSuccess(array $g, string $workItemId): void
    {
        $this->io->err(sprintf(
            "    %s %s  %s  %s  %s  → %s\n",
            Ansi::lgreen('✓'),
            sprintf('%-12s', $g['issueId']),
            Format::spent($g['minutes']),
            Format::type($g['type']),
            self::padTitle($this->issueData->titles()[$g['issueId']] ?? '', 30),
            $workItemId,
        ));
    }

    /** @param array{date: string, issueId: string, type: string, minutes: int, ids: list<int>, notes: list<string>} $g */
    private function printPushFailure(array $g, string $reason): void
    {
        $this->io->err(sprintf(
            "    %s %s  %s  %s  %s  — %s\n",
            Ansi::red('✗'),
            sprintf('%-12s', $g['issueId']),
            Format::spent($g['minutes']),
            Format::type($g['type']),
            self::padTitle($this->issueData->titles()[$g['issueId']] ?? '', 30),
            Ansi::red($reason),
        ));
    }

    /** @param array{date: string, issueId: string, type: string, minutes: int, ids: list<int>, notes: list<string>} $g */
    private function printPushSkipped(array $g): void
    {
        $this->io->err(sprintf(
            "    %s %s  %s  %s  %s  %s\n",
            Ansi::lyellow('—'),
            sprintf('%-12s', $g['issueId']),
            Format::spent($g['minutes']),
            Format::type($g['type']),
            self::padTitle($this->issueData->titles()[$g['issueId']] ?? '', 30),
            Ansi::lyellow('zero minutes'),
        ));
    }

    private static function padTitle(string $title, int $width): string
    {
        $truncated = mb_strimwidth($title, 0, $width, '…');

        return $truncated . str_repeat(' ', max(0, $width - mb_strwidth($truncated)));
    }

}
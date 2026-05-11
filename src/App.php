<?php declare(strict_types=1);

namespace Timeshit;

use Collator;
use DateTimeImmutable;
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
use Timeshit\View\WorkView;
use Timeshit\Youtrack\CachedIssueDataProvider;
use Timeshit\Youtrack\CachedTypeProvider;
use Timeshit\Youtrack\IssueCache;
use Timeshit\Youtrack\IssueDataProvider;
use Timeshit\Youtrack\TypeProvider;
use Timeshit\Youtrack\WorkItemCache;
use Timeshit\Youtrack\WorkItemType;
use Timeshit\Youtrack\WorkItemTypeCache;
use Timeshit\Youtrack\YoutrackClient;
use function array_values;
use function count;
use function ctype_digit;
use function date_default_timezone_set;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function rtrim;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function system;
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

    public function __construct(
        private readonly Config $config,
        private readonly RecordStore $store,
        private readonly TypeProvider $types,
        private readonly IssueDataProvider $issueData,
        private readonly Clock $clock,
        private readonly Io $io,
        private readonly Configurator $configurator,
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
            store: new FileRecordStore($rootDir . self::RECORDS_FILE),
            types: $types,
            issueData: $issueData,
            clock: new SystemClock(),
            io: $io,
            configurator: new Configurator($rootDir, $io),
        );
    }

    private const COMMAND_NAMES = [
        'status', 'issues', 'remote', 'local', 'all', //'types',
        'track', 'interrupt', 'meeting', 'day', 'vacation', 'type', 'switch',
        'pause', 'resume', 'skip', 'grab', 'put', 'end', 'done', 'note', 'edit', 'refresh',
        'at', 'before', 'after',
        'checkout',
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

        try {
            match ($resolved) {
                'help' => self::printHelp($this->io, $this->config),
                'issues' => $this->cmdIssues(),
                'remote' => $this->cmdRemote(),
                'local' => $this->cmdLocal(),
                'all' => $this->cmdAll(),
                'status' => $this->cmdStatus(),
                'refresh' => $this->cmdRefresh(),
                'track' => $this->cmdTrack($argv[2] ?? null, $argv[3] ?? null),
                'interrupt' => $this->cmdInterrupt($argv[2] ?? null, $argv[3] ?? null),
                'meeting' => $this->cmdMeeting(Resolver::restArgs($argv)),
                'day' => $this->cmdDay($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'vacation' => $this->cmdVacation($argv[2] ?? null, $argv[3] ?? null),
                'checkout' => $this->cmdCheckout($argv[2] ?? null, $argv[3] ?? null),
                'type' => $this->cmdType($argv[2] ?? null),
                'types' => $this->cmdTypes(),
                'switch' => $this->cmdSwitch($argv[2] ?? null),
                'pause' => $this->cmdPause(Resolver::restArgs($argv)),
                'resume' => $this->cmdResume(Resolver::restArgs($argv)),
                'skip' => $this->cmdSkip($argv[2] ?? null),
                'grab' => $this->cmdGrab($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'put' => $this->cmdPut($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'end' => $this->cmdEnd(Resolver::restArgs($argv)),
                'done' => $this->cmdDone(Resolver::restArgs($argv)),
                'note' => $this->cmdNote(Resolver::restArgs($argv)),
                'edit' => $this->cmdEdit($argv[2] ?? null),
                'at' => $this->cmdAt($argv[2] ?? null),
                'before' => $this->cmdBefore($argv[2] ?? null),
                'after' => $this->cmdAfter($argv[2] ?? null),
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
        $req = static fn(string $name): string => Ansi::yellow("<{$name}>");
        $opt = static fn(string $name): string => Ansi::lblack("[") . Ansi::yellow("<{$name}>") . Ansi::lblack("]");
        $val = static fn(string $name): string => Ansi::lgreen($name);
        $app = static fn(string $name): string => Ansi::lblue($name);

        $groups = [
            'Lists' => [
                [$cmd('status'),   '', 'Show the currently active record (if any) and the last closed one'],
                [$cmd('issues'),   '', 'List ' . $app('YouTrack') . ' issues you are involved in (cached for 24h)'],
                [$cmd('remote'),   '', 'List time entries already on ' . $app('YouTrack') . ' (cached for 24h)'],
                [$cmd('local'),    '', 'List locally tracked records not yet synced to ' . $app('YouTrack')],
                [$cmd('all'),      '', 'List time entries from both ' . $cmd('remote') . ' and ' . $cmd('local') . ' (' . Ansi::lgreen('●') . ' synced / ' . Ansi::lyellow('○') . ' local / ' . Ansi::red('✗') . ' failed)'],
                //[$cmd('types'),    '', 'List the ' . $app('YouTrack') . ' work-item types (cached for 24h)'],
            ],
            'Actions' => [
                [$cmd('track'),    $req('issue') . ' ' . $opt('type'), 'Start tracking of ' . $req('issue')],
                [$cmd('interrupt'),$req('issue') . ' ' . $opt('type'), 'Like ' . $cmd('track') . ', but mark the currently open record as paused (auto-resumed by ' . $cmd('done') . ')'],
                [$cmd('meeting'),  $opt('note'), 'Like ' . $cmd('interrupt') . ', but uses ' . Ansi::lgreen('defaultMeetingIssue') . ' / ' . Ansi::lgreen('defaultMeetingType') . ' from config'],
                [$cmd('pause'),    $opt('note'), 'Pause the current entry'],
                [$cmd('resume'),   $opt('note'), 'Resume tracking from the most recent entry'],
                [$cmd('done'),     $opt('note'), 'End the current entry and auto-resume the most recently interrupted one'],
                [$cmd('end'),      $opt('note'), 'End the current entry'],
                [$cmd('switch'),   $req('type'), 'End current entry and start same one with different ' . $req('type')],
                [$cmd('skip'),     $req('span'), 'End the open record ' . $req('span') . ' ago and immediately open a new one'],
                [$cmd('grab'),     $req('issue') . ' ' . $req('span') . ' ' . $opt('type'), 'Grab a ' . $req('span') . '-long time from the open record and fill it with ' . $req('issue')],
                [$cmd('put'),      $req('issue') . ' ' . $req('span') . ' ' . $opt('type'), 'Add a closed ' . $req('span') . '-long record for ' . $req('issue') . ' starting at midnight (for untracked time)'],
                [$cmd('day'),      $req('issue') . ' ' . $opt('date') . ' ' . $opt('type'), 'Log a full 8h day for ' . $req('issue') . ' on ' . $req('date') . ', default type: ' . Ansi::lgreen($config->defaultDayType)],
                [$cmd('vacation'), $req('date') . ' ' . $req('date'), 'Log a full 8h day on every working day between the two ' . $req('date') . 's (inclusive)'],
            ],
            'Edits' => [
                [$cmd('at'),       $req('time'), 'Set the start time (open record) or end time (closed record) of the last non-day record'],
                [$cmd('before'),   $req('span'), 'Move the start (open) or end (closed) of the last non-day record earlier by ' . $req('span')],
                [$cmd('after'),    $req('span'), 'Move the end of the last closed non-day record later by ' . $req('span')],
                [$cmd('type'),     $req('type'), 'Change the type of the current entry'],
                [$cmd('note'),     $req('note'), 'Add a note to the current entry'],
                [$cmd('edit'),     $req('id'),   'Open record ' . $req('id') . ' in the configured ' . Ansi::lgreen('editor') . ' for free-form editing'],
            ],
            'Sync' => [
                [$cmd('refresh'),  '', 'Refresh all caches from YouTrack'],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'), 'Switch tracking on git checkout (called from ' . $cmd('hooks/post-checkout') . ')'],
            ],
        ];

        $typeDesc = 'work-item type; see ' . $cmd('types') . ' for the allowed list'
            . '. Default: ' . Ansi::lgreen($config->defaultTrackType) . ' (' . $cmd('track') . ' / ' . $cmd('grab') . ')';

        $argRows = [
            [$req('issue'),   'YouTrack issue id, e.g. ' . $val('SW-1234') . ' or just ' . $val('1234')],
            [$req('type'),    $typeDesc],
            [$req('span'),    'duration like ' . $val('30m') . ', ' . $val('1h 20m') . ', ' . $val('1d 4h 15m') . ' (units ' . $val('d') . '/' . $val('h') . '/' . $val('m') . ', case- and whitespace-insensitive)'],
            [$req('time'),    $val('HH:MM') . ' or full date+time (e.g. ' . $val('2026-05-09 10:00') . ')'],
            [$req('date'),    'expressions like ' . $val('yesterday') . ' / ' . $val('yes') . ' / ' . $val('y') . ', day-of-month int (e.g. ' . $val('15') . '), or full date. Default: ' . $val('today')],
            [$req('note'),    'free-form text; appended to the record\'s existing note'],
            [$req('id'),      'numeric record id (the ' . Ansi::lblack('#N') . ' column shown by ' . $cmd('local') . ' / ' . $cmd('status') . ')'],
            [$req('branch'),  'git branch name (used to extract the issue id; passed by git hook)'],
            [$req('repo'),    'repository name (passed by git hook)'],
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
    }

    private function cmdIssues(): void
    {
        $data = $this->issueData->loadOrFetch();
        (new IssuesView($this->config->youtrackBaseUrl, $data['user']))->render($data['issues'], $data['workItems']);
    }

    private function cmdRemote(): void
    {
        $data = $this->issueData->loadOrFetch();
        (new WorkView($this->config->youtrackBaseUrl))->render($data['workItems'], $data['issues']);
    }

    private function cmdLocal(): void
    {
        $items = $this->store->load();
        (new RecordsView($this->config->youtrackBaseUrl))->render($items, $this->issueData->titles());
    }

    private function cmdAll(): void
    {
        $data = $this->issueData->loadOrFetch();
        $records = $this->store->load();
        (new AllView($this->config->youtrackBaseUrl))->render($data['workItems'], $records, $data['issues']);
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
            $this->io->out("No tracking records.\n");

            return;
        }
        $baseUrl = rtrim($this->config->youtrackBaseUrl, '/');
        $titleByIssueId = $this->issueData->titles();
        if ($active !== null) {
            $this->io->out(Ansi::lwhite('Active') . ":\n");
            $this->io->out($this->statusLine($active, $baseUrl, $titleByIssueId) . "\n");
        } else {
            $this->io->out(Ansi::lblack('No active record.') . "\n");
        }
        if ($previous !== null) {
            $this->io->out("\n" . Ansi::lwhite('Previous') . ":\n");
            $this->io->out($this->statusLine($previous, $baseUrl, $titleByIssueId) . "\n");
        }
    }

    /** @param array<string, string> $titleByIssueId */
    private function statusLine(Record $r, string $baseUrl, array $titleByIssueId): string
    {
        $today = $this->clock->now()->format('Y-m-d');
        $startDate = substr($r->startedAt, 0, 10);
        $startStr = $startDate === $today ? substr($r->startedAt, 11) : $r->startedAt;

        if ($r->endedAt === null) {
            $now = $this->clock->nowMinute();
            $duration = Format::duration($r->startedAt, $now) . ' so far';
            $timeRange = $startStr . ' → ' . Ansi::lgreen('…');
        } else {
            $endDate = substr($r->endedAt, 0, 10);
            $endStr = $endDate === $today && $startDate === $today
                ? substr($r->endedAt, 11)
                : $r->endedAt;
            $duration = Format::duration($r->startedAt, $r->endedAt);
            $timeRange = $startStr . Ansi::lblack('–') . $endStr;
        }

        $url = $baseUrl . '/issue/' . $r->issueId;
        $line = sprintf(
            '  %s %s  %s  %s  %s  %s',
            Ansi::link($url, $r->issueId),
            Format::recordId($r->id),
            Format::type($r->type),
            $timeRange,
            Ansi::lblack('(' . $duration . ')'),
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

    private function cmdType(?string $newType): void
    {
        $matched = Resolver::resolveType('type', $newType, null, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
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
                    "Stopped %s %s (%s) after %s\n",
                    $stopped->issueId,
                    Format::recordId($stopped->id),
                    $stopped->type,
                    Format::duration($stopped->startedAt, $stopped->endedAt),
                ));
            }
            $this->io->err(sprintf("Tracking %s %s as %s\n", $next->issueId, Format::recordId($next->id), $matched));
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
        $endedAt = $item->endedAt ?? '';
        $this->io->err(sprintf(
            "Stopped %s %s after %s\n",
            $item->issueId,
            Format::recordId($item->id),
            Format::duration($item->startedAt, $endedAt),
        ));
        if ($item->note !== '') {
            $this->io->err(sprintf("Note: %s\n", $item->note));
        }
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
            $endedAt = $item->endedAt ?? '';
            $this->io->err(sprintf(
                "Stopped %s %s after %s\n",
                $item->issueId,
                Format::recordId($item->id),
                Format::duration($item->startedAt, $endedAt),
            ));
            if ($item->note !== '') {
                $this->io->err(sprintf("Note: %s\n", $item->note));
            }

            $items = $this->store->load();
            $target = null;
            for ($i = count($items) - 2; $i >= 0; $i--) {
                if ($items[$i]->status === 'paused') {
                    $target = $items[$i];
                    break;
                }
            }
            if ($target === null) {
                return;
            }
            $now = $this->clock->nowMinute();
            $next = new Record(
                id: $this->store->nextId(),
                issueId: $target->issueId,
                type: $target->type,
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, 'done'),
            );
            $this->store->track($next, 'done');
            $this->io->err(sprintf("Resumed %s %s as %s\n", $next->issueId, Format::recordId($next->id), $next->type));
        });
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
                    "Paused %s %s after %s\n",
                    $stopped->issueId,
                    Format::recordId($stopped->id),
                    Format::duration($stopped->startedAt, $stopped->endedAt),
                ));
            }
            if ($break->note !== '') {
                $this->io->err(sprintf("Note: %s\n", $break->note));
            }
        });
    }

    private function cmdResume(?string $note): void
    {
        $this->store->transaction(function () use ($note): void {
            $items = $this->store->load();
            if ($items === []) {
                throw new RuntimeException('resume: no record to resume');
            }
            $last = $items[count($items) - 1];
            if ($last->isOpen() && $last->status !== 'untracked') {
                throw new RuntimeException('resume: a record is already open');
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
                throw new RuntimeException('resume: no record to resume');
            }
            $now = $this->clock->nowMinute();
            $next = new Record(
                id: $this->store->nextId(),
                issueId: $target->issueId,
                type: $target->type,
                startedAt: $now,
                endedAt: null,
                log: Record::logCreated($now, 'resume'),
                note: $note ?? '',
            );
            $this->store->track($next, 'resume');
            $this->io->err(sprintf("Resumed %s %s as %s\n", $next->issueId, Format::recordId($next->id), $next->type));
            if ($next->note !== '') {
                $this->io->err(sprintf("Note: %s\n", $next->note));
            }
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
            if ($endDt === false) {
                throw new RuntimeException("skip: invalid span");
            }
            try {
                $startDt = new DateTimeImmutable($last->startedAt);
            } catch (Exception) {
                throw new RuntimeException("skip: invalid existing time '{$last->startedAt}'");
            }
            if ($endDt <= $startDt) {
                throw new RuntimeException("skip: span too large — would end at or before the open record's start ({$last->startedAt})");
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
                "Skipped %s of %s %s: ended at %s, restarted at %s\n",
                Format::duration($endedAt, $now),
                $last->issueId,
                Format::recordId($last->id),
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
            if ($splitDt === false) {
                throw new RuntimeException("grab: invalid span");
            }
            try {
                $startDt = new DateTimeImmutable($last->startedAt);
            } catch (Exception) {
                throw new RuntimeException("grab: invalid existing time '{$last->startedAt}'");
            }
            if ($splitDt <= $startDt) {
                throw new RuntimeException("grab: span too large — would split at or before the open record's start ({$last->startedAt})");
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
                "Grabbed %s of %s %s (%s) from %s %s between %s and %s\n",
                Format::duration($splitAt, $now),
                $issueId,
                Format::recordId($grabbed->id),
                $resolvedType,
                $last->issueId,
                Format::recordId($last->id),
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
            if ($endDt === false) {
                throw new RuntimeException('put: invalid span');
            }
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
                "Put %s %s as %s (%s)\n",
                $issueId,
                Format::recordId($record->id),
                $resolvedType,
                Format::duration($start, $end),
            ));
        });
    }

    private function cmdNote(?string $note): void
    {
        if ($note === null || $note === '') {
            throw new RuntimeException('note: missing <text>');
        }
        $result = $this->store->noteLast($note, $this->clock->nowMinute(), 'note');
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('note: no record to add note to');
        }
        if (!$result['changed']) {
            return;
        }
        $where = $item->isOpen() ? 'active' : 'last closed';
        $this->io->err(sprintf("Note on %s %s (%s): %s\n", $item->issueId, Format::recordId($item->id), $where, $item->note));
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
            foreach ($this->store->load() as $r) {
                if ($r->id === $id) {
                    return $r;
                }
            }

            return null;
        });
        if ($target === null) {
            throw new RuntimeException("edit: record #{$id} not found");
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
            $exitCode = 0;
            system($cmd, $exitCode);
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
            $index = null;
            foreach ($items as $i => $r) {
                if ($r->id === $target->id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException("edit: record #{$target->id} disappeared during edit");
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
                "Edited %s %s (%s)\n",
                $final->issueId,
                Format::recordId($final->id),
                $summary,
            ));
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
        $this->startRecord($issueId, $resolvedType, 'interrupt', forceInterruptIfOpen: true);
    }

    private function cmdMeeting(?string $note): void
    {
        $resolved = $note === '' ? null : $note;
        $this->startRecord(
            $this->config->defaultMeetingIssue,
            $this->config->defaultMeetingType,
            'meeting',
            forceInterruptIfOpen: true,
            note: $resolved,
        );
    }

    private function cmdDay(?string $issue, ?string $date, ?string $type): void
    {
        $issueId = $this->resolveIssueArg('day', $issue);
        $when = Resolver::resolveDate($date);
        $resolvedType = Resolver::resolveType('day', $type, $this->config->defaultDayType, $this->types->types(...), $this->config->allowedTypes, $this->config->typeAliases);
        $dayKey = $when->format('Y-m-d');
        $this->store->transaction(function () use ($issueId, $when, $resolvedType, $dayKey): void {
            foreach ($this->store->load() as $existing) {
                if ($existing->status !== 'day') {
                    continue;
                }
                if ((new DateTimeImmutable($existing->startedAt))->format('Y-m-d') === $dayKey) {
                    throw new RuntimeException(
                        "day: a full-day record already exists on {$dayKey} ({$existing->issueId}, {$existing->type})",
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
        $fromDt = Resolver::resolveDate($from, 'vacation')->setTime(0, 0);
        $toDt = Resolver::resolveDate($to, 'vacation')->setTime(0, 0);
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
                        "vacation: a full-day record already exists on {$key} ({$clash->issueId}, {$clash->type})",
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
        $this->startRecord(Resolver::extractIssueId($branch), null, 'checkout');
    }

    private function resolveIssueArg(string $cmd, ?string $issue): string
    {
        $issueId = Resolver::requireIssueId($cmd, $issue, $this->config->defaultIssuePrefix);
        if (!Resolver::isStandardIssueId($issueId)) {
            $this->io->err(Ansi::lyellow("Warning: '{$issueId}' has unusual issue id format (expected ABC-123)") . "\n");
        }

        return $issueId;
    }

    private function startRecord(
        string $issueId,
        ?string $type,
        string $trigger,
        bool $forceInterruptIfOpen = false,
        ?string $note = null,
    ): void {
        $resolvedType = $type === null || $type === '' ? $this->config->defaultTrackType : $type;

        $this->store->transaction(function () use ($issueId, $resolvedType, $trigger, $forceInterruptIfOpen, $note): void {
            $items = $this->store->load();
            $last = $items === [] ? null : $items[count($items) - 1];
            $pauseClosed = false;
            if ($last !== null && $last->isOpen()) {
                if ($forceInterruptIfOpen) {
                    $pauseClosed = true;
                } elseif ($last->type === $this->config->defaultTrackType
                    && in_array($resolvedType, $this->config->interruptionTypes, true)
                ) {
                    $pauseClosed = true;
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
            $result = $this->store->track($next, $trigger, $pauseClosed);
            if (!$result['started']) {
                return;
            }
            $stopped = $result['stopped'];
            if ($stopped !== null && $stopped->endedAt !== null) {
                $this->io->err(sprintf(
                    "Stopped %s %s after %s\n",
                    $stopped->issueId,
                    Format::recordId($stopped->id),
                    Format::duration($stopped->startedAt, $stopped->endedAt),
                ));
            }
            $this->io->err(sprintf("Tracking %s %s as %s\n", $next->issueId, Format::recordId($next->id), $resolvedType));
            if ($next->note !== '') {
                $this->io->err(sprintf("Note: %s\n", $next->note));
            }
        });
    }

    private function cmdAt(?string $time): void
    {
        $this->modifyTimes('at', 'set', $time);
    }

    private function cmdBefore(?string $span): void
    {
        $this->modifyTimes('before', 'sub', $span);
    }

    private function cmdAfter(?string $span): void
    {
        $this->modifyTimes('after', 'add', $span);
    }

    private function modifyTimes(string $cmd, string $mode, ?string $arg): void
    {
        $this->store->transaction(function () use ($cmd, $mode, $arg): void {
            $this->modifyTimesLocked($cmd, $mode, $arg);
        });
    }

    private function modifyTimesLocked(string $cmd, string $mode, ?string $arg): void
    {
        $items = $this->store->load();

        $targetIndex = null;
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if ($items[$i]->status === 'day') {
                continue;
            }
            $targetIndex = $i;
            break;
        }
        if ($targetIndex === null) {
            throw new RuntimeException("{$cmd}: no record to modify");
        }

        $target = $items[$targetIndex];
        $isOpen = $target->isOpen();

        if ($cmd === 'after' && $isOpen) {
            throw new RuntimeException('after: last record is open (use before/at to move its start)');
        }

        if ($isOpen) {
            $existing = $target->startedAt;
        } else {
            $existing = $target->endedAt;
            if ($existing === null) {
                throw new RuntimeException("{$cmd}: closed record has no end time");
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
            $modified = $existingDt->modify("{$sign}{$spanMin} minutes");
            if ($modified === false) {
                throw new RuntimeException("{$cmd}: invalid span");
            }
            $newDt = $modified;
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
                    throw new RuntimeException("{$cmd}: invalid resulting timestamps on previous record");
                }
                if ($sDt >= $eDt) {
                    throw new RuntimeException("{$cmd}: would result in non-positive duration on previous record ({$adjusted->startedAt} → {$adjusted->endedAt})");
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
            $duration = Format::duration($r->startedAt, $now) . ' so far';
            $end = '  ...';
        } else {
            $duration = Format::duration($r->startedAt, $r->endedAt);
            $end = substr($r->endedAt, 11);
        }

        return sprintf(
            '  %-9s %s  %-22s  %s → %-5s  (%s)',
            $r->issueId,
            Format::recordId($r->id),
            $r->type,
            $start,
            $end,
            $duration,
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

}
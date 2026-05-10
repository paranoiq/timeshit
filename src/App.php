<?php declare(strict_types=1);

namespace Timeshit;

use Collator;
use DateTimeImmutable;
use Exception;
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
use function date_default_timezone_set;
use function in_array;
use function max;
use function rtrim;
use function sprintf;
use function str_repeat;
use function strtolower;
use function substr;
use function trim;
use function usort;

final class App
{

    private const ISSUES_CACHE_FILE = '/data/issues.neon';
    private const WORK_ITEMS_CACHE_FILE = '/data/work-items.neon';
    private const WORK_ITEM_TYPES_FILE = '/data/work-item-types.neon';
    private const RECORDS_FILE = '/data/records.neon';

    private const TRACK_DEFAULT_TYPE = 'Implementation';
    private const DAY_DEFAULT_TYPE = 'Out of office';

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
        'status', 'issues', 'pushed', 'local', 'all', 'types',
        'track', 'day', 'type', 'switch',
        'pause', 'resume', 'skip', 'steal', 'end', 'comment', 'refresh',
        'at', 'before', 'after',
        'checkout',
        'configure',
        'help',
    ];

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $input = $argv[1] ?? null;

        if ($input === null || $input === '' || $input === '-h' || $input === '--help') {
            self::printHelp($this->io);

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
                'help' => self::printHelp($this->io),
                'issues' => $this->cmdIssues(),
                'pushed' => $this->cmdPushed(),
                'local' => $this->cmdLocal(),
                'all' => $this->cmdAll(),
                'status' => $this->cmdStatus(),
                'refresh' => $this->cmdRefresh(),
                'track' => $this->cmdTrack($argv[2] ?? null, $argv[3] ?? null),
                'day' => $this->cmdDay($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'checkout' => $this->cmdCheckout($argv[2] ?? null, $argv[3] ?? null),
                'type' => $this->cmdType($argv[2] ?? null),
                'types' => $this->cmdTypes(),
                'switch' => $this->cmdSwitch($argv[2] ?? null),
                'pause' => $this->cmdPause(Resolver::restArgs($argv)),
                'resume' => $this->cmdResume(Resolver::restArgs($argv)),
                'skip' => $this->cmdSkip($argv[2] ?? null),
                'steal' => $this->cmdSteal($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'end' => $this->cmdEnd(Resolver::restArgs($argv)),
                'comment' => $this->cmdComment(Resolver::restArgs($argv)),
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
        self::printHelp($this->io);
        exit(1);
    }

    private function ambiguousCommand(string $message): never
    {
        $this->io->err(Ansi::red($message) . "\n\n");
        self::printHelp($this->io);
        exit(1);
    }

    public static function printHelp(Io $io): void
    {
        $cmd = static fn(string $name): string => Ansi::lgreen($name);
        $req = static fn(string $name): string => Ansi::yellow("<{$name}>");
        $opt = static fn(string $name): string => Ansi::lblack("[") . Ansi::yellow("<{$name}>") . Ansi::lblack("]");
        $val = static fn(string $name): string => Ansi::lgreen($name);

        $groups = [
            'Lists' => [
                [$cmd('status'),   '', 'Show the currently active record (if any) and the last closed one'],
                [$cmd('issues'),   '', 'List YouTrack issues you are involved in (cached for 24h)'],
                [$cmd('pushed'),   '', 'List time entries pushed to YouTrack grouped by week and day (cached for 24h)'],
                [$cmd('local'),    '', 'List locally tracked records not yet synced to YouTrack'],
                [$cmd('all'),      '', 'List time entries from both ' . $cmd('pushed') . ' and ' . $cmd('local') . ' (' . Ansi::lgreen('●') . ' synced / ' . Ansi::lyellow('○') . ' local / ' . Ansi::red('✗') . ' failed)'],
                //[$cmd('types'),    '', 'List the YouTrack work-item types (cached for 24h)'],
            ],
            'Time' => [
                [$cmd('track'),    $req('issue') . ' ' . $opt('type'), 'Manually switch local time tracking to ' . $req('issue')],
                [$cmd('pause'),    $opt('comment'), 'Pause the currently tracked record'],
                [$cmd('resume'),   $opt('comment'), 'Resume tracking from the most recent record'],
                [$cmd('end'),      $opt('comment'), 'End the currently tracked entry'],
                [$cmd('skip'),     $req('offset'), 'End the open record ' . $req('offset') . ' ago and immediately open a new one (e.g. forgotten lunch break)'],
                [$cmd('steal'),    $req('issue') . ' ' . $req('offset') . ' ' . $opt('type'), 'Punch a ' . $req('offset') . '-long hole in the open record and fill it with ' . $req('issue')],
                [$cmd('at'),       $req('time'), 'Set the start time (open record) or end time (closed record) of the last non-day record'],
                [$cmd('before'),   $req('offset'), 'Move the start (open) or end (closed) of the last non-day record earlier by ' . $req('offset')],
                [$cmd('after'),    $req('offset'), 'Move the end of the last closed non-day record later by ' . $req('offset')],
                [$cmd('day'),      $req('issue') . ' ' . $opt('date') . ' ' . $opt('type'), 'Log a full 8h day for ' . $req('issue') . ' on ' . $req('date')],
            ],
            'Options' => [
                [$cmd('type'),     $req('type'), 'Change the type of the currently tracked entry'],
                [$cmd('switch'),   $req('type'), 'End the current entry and open a new one with the same properties and the given ' . $req('type')],
                [$cmd('comment'),  $req('comment'), 'Add a comment on the currently tracked entry'],
            ],
            'Sync' => [
                [$cmd('refresh'),  '', 'Force-refresh all caches from YouTrack'],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'), 'Switch tracking on git checkout (called from ' . $cmd('hooks/post-checkout') . ')'],
            ],
        ];

        $argRows = [
            [$req('issue'),   'YouTrack issue id, e.g. ' . $val('ABC-123')],
            [$req('type'),    'work-item type; see ' . $cmd('types') . ' for the allowed list. Default: ' . Ansi::lgreen(self::TRACK_DEFAULT_TYPE) . ' (' . $cmd('track') . ' / ' . $cmd('steal') . '), ' . Ansi::lyellow(self::DAY_DEFAULT_TYPE) . ' (' . $cmd('day') . ')'],
            [$req('offset'),  'duration like ' . $val('30m') . ', ' . $val('1h 20m') . ', ' . $val('1d 4h 15m') . ' (units ' . $val('d') . '/' . $val('h') . '/' . $val('m') . ', case- and whitespace-insensitive)'],
            [$req('time'),    $val('HH:MM') . ' (keeps the date of the existing timestamp) or full date+time (e.g. ' . $val('2026-05-09 10:00') . ')'],
            [$req('date'),    $val('today') . ' / ' . $val('yesterday') . ' / ' . $val('tomorrow') . ' (and unique prefixes like ' . $val('y') . ', ' . $val('over') . '), day-of-month int (e.g. ' . $val('15') . '), or ISO (e.g. ' . $val('2026-05-08') . '). Default: ' . $val('today')],
            [$req('comment'), 'free-form text; appended to the record\'s existing comment with ' . $val(' | ') . ' separator'],
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

        $io->out(Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + Git +  GitLab') . "\n\n");
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

    private function cmdPushed(): void
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
            if ($r->startTrigger === 'day') {
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
            '  %s %s  %s  %s  %s',
            Ansi::link($url, $r->issueId),
            Format::recordId($r->id),
            Format::type($r->type),
            $timeRange,
            Ansi::lblack('(' . $duration . ')'),
        );
        if ($r->branch !== null) {
            $line .= '  ' . Ansi::lblack($r->branch);
        }
        $title = $titleByIssueId[$r->issueId] ?? '';
        if ($title !== '') {
            $line .= "\n      " . $title;
        }
        if ($r->comment !== '') {
            $line .= "\n      " . Ansi::lblack('"' . $r->comment . '"');
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
        $matched = Resolver::resolveType('type', $newType, null, $this->types->types(...), $this->config->allowedTypes);
        $result = $this->store->changeOpenType($matched, $this->clock->nowMinute());
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
        $matched = Resolver::resolveType('switch', $newType, null, $this->types->types(...), $this->config->allowedTypes);
        $items = $this->store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('switch: no open tracking entry');
        }
        $trigger = 'switched';
        $now = $this->clock->nowMinute();
        $next = new Record(
            id: $this->store->nextId(),
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $matched,
            startedAt: $now,
            startTrigger: $trigger,
            endedAt: null,
            endTrigger: null,
            createdAt: $now,
            modifiedAt: $now,
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
        $onBranch = $next->branch === null ? '' : " ({$next->branch})";
        $inRepo = $next->repo === '' ? '' : " in {$next->repo}";
        $this->io->err(sprintf("Tracking %s %s%s%s as %s\n", $next->issueId, Format::recordId($next->id), $onBranch, $inRepo, $matched));
    }

    private function cmdEnd(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = $this->store->endOpen($this->clock->nowMinute(), 'ended', $resolved);
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
        if ($item->comment !== '') {
            $this->io->err(sprintf("Comment: %s\n", $item->comment));
        }
    }

    private function cmdPause(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = $this->store->endOpen($this->clock->nowMinute(), 'paused', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('pause: no open tracking entry');
        }
        $endedAt = $item->endedAt ?? '';
        $this->io->err(sprintf(
            "Paused %s %s after %s\n",
            $item->issueId,
            Format::recordId($item->id),
            Format::duration($item->startedAt, $endedAt),
        ));
        if ($item->comment !== '') {
            $this->io->err(sprintf("Comment: %s\n", $item->comment));
        }
    }

    private function cmdResume(?string $comment): void
    {
        $items = $this->store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null) {
            throw new RuntimeException('resume: no record to resume');
        }
        if ($last->isOpen()) {
            throw new RuntimeException('resume: a record is already open');
        }
        $now = $this->clock->nowMinute();
        $next = new Record(
            id: $this->store->nextId(),
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $last->type,
            startedAt: $now,
            startTrigger: 'resumed',
            endedAt: null,
            endTrigger: null,
            createdAt: $now,
            modifiedAt: $now,
            comment: $comment ?? '',
        );
        $this->store->track($next, 'resumed');
        $onBranch = $next->branch === null ? '' : " ({$next->branch})";
        $this->io->err(sprintf("Resumed %s %s%s as %s\n", $next->issueId, Format::recordId($next->id), $onBranch, $next->type));
        if ($next->comment !== '') {
            $this->io->err(sprintf("Comment: %s\n", $next->comment));
        }
    }

    private function cmdSkip(?string $offset): void
    {
        $offsetMin = Resolver::parseOffset('skip', $offset);

        $items = $this->store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('skip: no open tracking entry');
        }

        $nowDt = $this->clock->now();
        $endDt = $nowDt->modify("-{$offsetMin} minutes");
        if ($endDt === false) {
            throw new RuntimeException("skip: invalid offset");
        }
        try {
            $startDt = new DateTimeImmutable($last->startedAt);
        } catch (Exception) {
            throw new RuntimeException("skip: invalid existing time '{$last->startedAt}'");
        }
        if ($endDt <= $startDt) {
            throw new RuntimeException("skip: offset too large — would end at or before the open record's start ({$last->startedAt})");
        }

        $now = $nowDt->format('Y-m-d H:i');
        $endedAt = $endDt->format('Y-m-d H:i');

        $closed = $last->withEnd($endedAt, 'skipped', $now);
        $next = new Record(
            id: $this->store->nextId(),
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $last->type,
            startedAt: $now,
            startTrigger: 'skipped',
            endedAt: null,
            endTrigger: null,
            createdAt: $now,
            modifiedAt: $now,
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
    }

    private function cmdSteal(?string $issue, ?string $offset, ?string $type): void
    {
        $issueId = Resolver::requireIssueId('steal', $issue);
        $offsetMin = Resolver::parseOffset('steal', $offset);
        $resolvedType = Resolver::resolveType('steal', $type, self::TRACK_DEFAULT_TYPE, $this->types->types(...), $this->config->allowedTypes);

        $items = $this->store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('steal: no open tracking entry');
        }

        $nowDt = $this->clock->now();
        $splitDt = $nowDt->modify("-{$offsetMin} minutes");
        if ($splitDt === false) {
            throw new RuntimeException("steal: invalid offset");
        }
        try {
            $startDt = new DateTimeImmutable($last->startedAt);
        } catch (Exception) {
            throw new RuntimeException("steal: invalid existing time '{$last->startedAt}'");
        }
        if ($splitDt <= $startDt) {
            throw new RuntimeException("steal: offset too large — would split at or before the open record's start ({$last->startedAt})");
        }

        $now = $nowDt->format('Y-m-d H:i');
        $splitAt = $splitDt->format('Y-m-d H:i');

        $closed = $last->withEnd($splitAt, 'stolen', $now);
        $stolen = new Record(
            id: $this->store->nextId(),
            issueId: $issueId,
            branch: null,
            repo: '',
            type: $resolvedType,
            startedAt: $splitAt,
            startTrigger: 'stolen',
            endedAt: $now,
            endTrigger: 'stolen',
            createdAt: $now,
            modifiedAt: $now,
        );
        $continuation = new Record(
            id: $this->store->nextId(),
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $last->type,
            startedAt: $now,
            startTrigger: 'stolen',
            endedAt: null,
            endTrigger: null,
            createdAt: $now,
            modifiedAt: $now,
        );

        $items[count($items) - 1] = $closed;
        $items[] = $stolen;
        $items[] = $continuation;
        $this->store->save($items);

        $this->io->err(sprintf(
            "Stole %s of %s %s (%s) from %s %s between %s and %s\n",
            Format::duration($splitAt, $now),
            $issueId,
            Format::recordId($stolen->id),
            $resolvedType,
            $last->issueId,
            Format::recordId($last->id),
            substr($splitAt, 11),
            substr($now, 11),
        ));
    }

    private function cmdComment(?string $comment): void
    {
        if ($comment === null || $comment === '') {
            throw new RuntimeException('comment: missing <text>');
        }
        $result = $this->store->commentLast($comment, $this->clock->nowMinute());
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('comment: no record to comment on');
        }
        if (!$result['changed']) {
            return;
        }
        $where = $item->isOpen() ? 'active' : 'last closed';
        $this->io->err(sprintf("Comment on %s %s (%s): %s\n", $item->issueId, Format::recordId($item->id), $where, $item->comment));
    }

    private function cmdTrack(?string $issue, ?string $type): void
    {
        $issueId = Resolver::requireIssueId('track', $issue);
        $resolvedType = Resolver::resolveType('track', $type, self::TRACK_DEFAULT_TYPE, $this->types->types(...), $this->config->allowedTypes);
        $this->startRecord($issueId, null, '', $resolvedType, 'manual');
    }

    private function cmdDay(?string $issue, ?string $date, ?string $type): void
    {
        $issueId = Resolver::requireIssueId('day', $issue);
        $when = Resolver::resolveDate($date);
        $resolvedType = Resolver::resolveType('day', $type, self::DAY_DEFAULT_TYPE, $this->types->types(...), $this->config->allowedTypes);
        $dayKey = $when->format('Y-m-d');
        foreach ($this->store->load() as $existing) {
            if ($existing->startTrigger !== 'day') {
                continue;
            }
            if ((new DateTimeImmutable($existing->startedAt))->format('Y-m-d') === $dayKey) {
                throw new RuntimeException(
                    "day: a full-day record already exists on {$dayKey} ({$existing->issueId}, {$existing->type})",
                );
            }
        }
        $start = $when->setTime(9, 0);
        $end = $when->setTime(17, 0);
        $now = $this->clock->nowMinute();
        $record = new Record(
            id: $this->store->nextId(),
            issueId: $issueId,
            branch: null,
            repo: '',
            type: $resolvedType,
            startedAt: $start->format('Y-m-d H:i'),
            startTrigger: 'day',
            endedAt: $end->format('Y-m-d H:i'),
            endTrigger: 'day',
            createdAt: $now,
            modifiedAt: $now,
        );
        $this->store->appendClosed($record);
        $this->io->err(sprintf(
            "Logged %s %s for %s as %s (8h)\n",
            $issueId,
            Format::recordId($record->id),
            $dayKey,
            $resolvedType,
        ));
    }

    private function cmdCheckout(?string $branch, ?string $repo): void
    {
        if ($branch === null || $branch === '') {
            throw new RuntimeException('checkout: missing <branch>');
        }
        if ($repo === null || $repo === '') {
            throw new RuntimeException('checkout: missing <repo>');
        }
        $this->startRecord(Resolver::extractIssueId($branch), $branch, $repo, null, 'checkout');
    }

    private function startRecord(string $issueId, ?string $branch, string $repo, ?string $type, string $trigger): void
    {
        $resolvedType = $type === null || $type === '' ? self::TRACK_DEFAULT_TYPE : $type;
        $now = $this->clock->nowMinute();
        $next = new Record(
            id: $this->store->nextId(),
            issueId: $issueId,
            branch: $branch,
            repo: $repo,
            type: $resolvedType,
            startedAt: $now,
            startTrigger: $trigger,
            endedAt: null,
            endTrigger: null,
            createdAt: $now,
            modifiedAt: $now,
        );
        $result = $this->store->track($next, $trigger);
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
        $onBranch = $branch === null ? '' : " ({$branch})";
        $inRepo = $repo === '' ? '' : " in {$repo}";
        $this->io->err(sprintf("Tracking %s %s%s%s as %s\n", $next->issueId, Format::recordId($next->id), $onBranch, $inRepo, $resolvedType));
    }

    private function cmdAt(?string $time): void
    {
        $this->modifyTimes('at', 'set', $time);
    }

    private function cmdBefore(?string $offset): void
    {
        $this->modifyTimes('before', 'sub', $offset);
    }

    private function cmdAfter(?string $offset): void
    {
        $this->modifyTimes('after', 'add', $offset);
    }

    private function modifyTimes(string $cmd, string $mode, ?string $arg): void
    {
        $items = $this->store->load();

        $targetIndex = null;
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if ($items[$i]->startTrigger === 'day') {
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
            $offsetMin = Resolver::parseOffset($cmd, $arg);
            try {
                $existingDt = new DateTimeImmutable($existing);
            } catch (Exception) {
                throw new RuntimeException("{$cmd}: invalid existing time '{$existing}'");
            }
            $sign = $mode === 'sub' ? '-' : '+';
            $modified = $existingDt->modify("{$sign}{$offsetMin} minutes");
            if ($modified === false) {
                throw new RuntimeException("{$cmd}: invalid offset");
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
            $newItems[$targetIndex] = $target->withStartedAt($newValue, $modifiedAt);
            if ($targetIndex > 0) {
                $prev = $items[$targetIndex - 1];
                if ($prev->startTrigger !== 'day' && $prev->endedAt === $target->startedAt) {
                    $newItems[$targetIndex - 1] = $prev->withEndedAt($newValue, $modifiedAt);
                    $adjustedPrevIndex = $targetIndex - 1;
                }
            }
        } else {
            $newItems[$targetIndex] = $target->withEndedAt($newValue, $modifiedAt);
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
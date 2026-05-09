<?php declare(strict_types=1);

namespace Timeshit;

use Collator;
use DateTimeImmutable;
use Exception;
use Nette\Neon\Neon;
use RuntimeException;
use Throwable;
use Timeshit\Local\Record;
use Timeshit\Local\Store;
use Timeshit\View\IssuesView;
use Timeshit\View\RecordsView;
use Timeshit\View\WorkView;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\IssueCache;
use Timeshit\Youtrack\WorkItem;
use Timeshit\Youtrack\WorkItemCache;
use Timeshit\Youtrack\WorkItemType;
use Timeshit\Youtrack\WorkItemTypeCache;
use Timeshit\Youtrack\YoutrackClient;

use function array_values;
use function count;
use function date;
use function date_default_timezone_set;
use function dirname;
use function fgets;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fprintf;
use function fwrite;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function max;
use function mkdir;
use function sprintf;
use function str_repeat;
use function strtolower;
use function substr;
use function trim;
use function usort;

use const STDERR;
use const STDIN;

final class App
{

    private const CONFIG_FILE = '/config/config.neon';
    private const SECRETS_FILE = '/config/secrets.neon';
    private const ISSUES_CACHE_FILE = '/data/issues.neon';
    private const WORK_ITEMS_CACHE_FILE = '/data/work-items.neon';
    private const WORK_ITEM_TYPES_FILE = '/data/work-item-types.neon';
    private const RECORDS_FILE = '/data/records.neon';

    private const DEFAULT_TIMEZONE = 'Europe/Prague';

    private const TRACK_DEFAULT_TYPE = 'Implementation';
    private const DAY_DEFAULT_TYPE = 'Out of office';

    public function __construct(private readonly string $rootDir) {}

    private const COMMAND_NAMES = [
        'issues', 'work', 'records', 'types',
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
            $this->printHelp();

            return 0;
        }

        if (!is_file($this->rootDir . self::SECRETS_FILE)) {
            try {
                $this->cmdConfigure();
            } catch (Throwable $e) {
                fwrite(STDERR, Ansi::red("Error: " . $e->getMessage()) . "\n");

                return 1;
            }

            return 0;
        }

        date_default_timezone_set(Config::timezone($this->rootDir));

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
                'help' => $this->printHelp(),
                'issues' => $this->cmdIssues(Config::load($this->rootDir)),
                'work' => $this->cmdWork(Config::load($this->rootDir)),
                'records' => $this->cmdRecords(Config::load($this->rootDir)),
                'refresh' => $this->cmdRefresh(Config::load($this->rootDir)),
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
                'configure' => $this->cmdConfigure(),
                default => throw new RuntimeException("dispatch: no handler for resolved command '{$resolved}'"),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, Ansi::red("Error: " . $e->getMessage()) . "\n");

            return 1;
        }

        return 0;
    }

    private function unknownCommand(string $command): never
    {
        fwrite(STDERR, Ansi::red("Unknown command: {$command}") . "\n\n");
        $this->printHelp();
        exit(1);
    }

    private function ambiguousCommand(string $message): never
    {
        fwrite(STDERR, Ansi::red($message) . "\n\n");
        $this->printHelp();
        exit(1);
    }

    private function printHelp(): void
    {
        $cmd = static fn(string $name): string => Ansi::lgreen($name);
        $req = static fn(string $name): string => Ansi::yellow("<{$name}>");
        $opt = static fn(string $name): string => Ansi::lblack("[") . Ansi::yellow("<{$name}>") . Ansi::lblack("]");

        $groups = [
            'Lists' => [
                [$cmd('issues'),   '', 'List YouTrack issues you are involved in (cached for 24h)'],
                [$cmd('work'),     '', 'List your YouTrack work items grouped by week and day (cached for 24h)'],
                [$cmd('records'),  '', 'List locally tracked records not yet synced to YouTrack'],
                [$cmd('types'),    '', 'List the YouTrack work-item types (cached for 24h)'],
            ],
            'Time' => [
                [$cmd('track'),    $req('issue') . ' ' . $opt('type'), "Manually switch local time tracking to " . $req('issue') . " (type defaults to \"" . Ansi::lgreen(self::TRACK_DEFAULT_TYPE) . "\")"],
                [$cmd('pause'),    $opt('comment'), 'Pause the currently tracked record (optional ' . $req('comment') . ' attached to the record)'],
                [$cmd('resume'),   $opt('comment'), 'Resume tracking from the most recent record (optional ' . $req('comment') . ' on the new record)'],
                [$cmd('end'),      $opt('comment'), 'End the currently tracked entry (optional ' . $req('comment') . ' attached to the record)'],
                [$cmd('skip'),     $req('offset'), 'End the open record ' . $req('offset') . ' ago and immediately open a new one (e.g. forgotten lunch break)'],
                [$cmd('steal'),    $req('issue') . ' ' . $req('offset') . ' ' . $opt('type'), 'Punch a ' . $req('offset') . '-long hole in the open record and fill it with ' . $req('issue') . ' (type defaults to "' . Ansi::lgreen(self::TRACK_DEFAULT_TYPE) . '")'],
                [$cmd('at'),       $req('time'), 'Set the start time (open record) or end time (closed record) of the last non-day record. ' . $req('time') . ' is ' . Ansi::lgreen('HH:MM') . ' or full date+time'],
                [$cmd('before'),   $req('offset'), 'Move the start (open) or end (closed) of the last non-day record earlier by ' . $req('offset') . ' (e.g. ' . Ansi::lgreen('1h 20m') . ')'],
                [$cmd('after'),    $req('offset'), 'Move the end of the last closed non-day record later by ' . $req('offset')],
                [$cmd('day'),      $req('issue') . ' ' . $opt('date') . ' ' . $opt('type'), "Log a full 8h day (date defaults to today; accepts day-of-month int, " . Ansi::lgreen('y|yes|yesterday') . " etc.; type defaults to \"" . Ansi::lyellow(self::DAY_DEFAULT_TYPE) . '"'],
            ],
            'Options' => [
                [$cmd('type'),     $req('type'), 'Change the type of the currently tracked entry'],
                [$cmd('switch'),   $req('type'), 'End the current entry and open a new one with the same issue/branch/repo and the given ' . $req('type')],
                [$cmd('comment'),  $req('comment'), 'Add a comment on the currently tracked entry'],
            ],
            'Sync' => [
                [$cmd('refresh'),  '', 'Force-refresh all caches from YouTrack'],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'), "Switch tracking on git checkout (called from " . $cmd('hooks/post-checkout') . ")"],
            ],
        ];

        $nameWidth = 0;
        $argsWidth = 0;
        foreach ($groups as $rows) {
            foreach ($rows as [$name, $args]) {
                $nameWidth = max($nameWidth, Ansi::length($name));
                $argsWidth = max($argsWidth, Ansi::length($args));
            }
        }

        echo Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + Git +  GitLab') . "\n\n";
        echo "Usage: " . $cmd('timeshit.php') . " " . $req('command') . " " . $opt('args') . "\n\n";
        foreach ($groups as $title => $rows) {
            echo Ansi::lwhite($title) . ":\n";
            foreach ($rows as [$name, $args, $desc]) {
                echo "  " . $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2)
                    . $args . str_repeat(' ', $argsWidth - Ansi::length($args) + 2)
                    . $desc . "\n";
            }
        }
    }

    private function cmdIssues(Config $config): void
    {
        $data = $this->loadOrFetch($config);
        (new IssuesView($config->youtrackBaseUrl, $data['user']))->render($data['issues'], $data['workItems']);
    }

    private function cmdWork(Config $config): void
    {
        $data = $this->loadOrFetch($config);
        (new WorkView($config->youtrackBaseUrl))->render($data['workItems'], $data['issues']);
    }

    private function cmdRecords(Config $config): void
    {
        $items = (new Store($this->rootDir . self::RECORDS_FILE))->load();
        $titleByIssueId = [];
        $issueCachePath = $this->rootDir . self::ISSUES_CACHE_FILE;
        if (file_exists($issueCachePath)) {
            $issuesData = (new IssueCache($issueCachePath))->load();
            foreach ($issuesData['issues'] as $issue) {
                $titleByIssueId[$issue->id] = $issue->title;
            }
        }
        (new RecordsView($config->youtrackBaseUrl))->render($items, $titleByIssueId);
    }

    private function cmdTypes(): void
    {
        $types = $this->loadOrFetchTypes();
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
            $name = in_array($t->name, Resolver::ALLOWED_TYPES, true) ? Ansi::lgreen($t->name) : $t->name;
            echo $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2) . Ansi::lblack($t->id) . "\n";
        }
    }

    private function cmdRefresh(Config $config): void
    {
        $this->fetchAndCache($config);
        $this->fetchAndCacheTypes($config);
    }

    private function cmdType(?string $newType): void
    {
        $matched = Resolver::resolveType('type', $newType, null, $this->loadOrFetchTypes(...));
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->changeOpenType($matched);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('type: no open tracking entry to update');
        }
        if (!$result['changed']) {
            return;
        }
        fprintf(
            STDERR,
            "Changed type of %s from %s to %s\n",
            $item->issueId,
            (string) $result['previousType'],
            $matched,
        );
    }

    private function cmdSwitch(?string $newType): void
    {
        $matched = Resolver::resolveType('switch', $newType, null, $this->loadOrFetchTypes(...));
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('switch: no open tracking entry');
        }
        $trigger = 'switched';
        $now = date('Y-m-d H:i');
        $next = new Record(
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
        $result = $store->track($next, $trigger);
        if (!$result['started']) {
            return;
        }
        $stopped = $result['stopped'];
        if ($stopped !== null && $stopped->endedAt !== null) {
            fprintf(
                STDERR,
                "Stopped %s (%s) after %s\n",
                $stopped->issueId,
                $stopped->type,
                Format::duration($stopped->startedAt, $stopped->endedAt),
            );
        }
        $onBranch = $next->branch === null ? '' : " ({$next->branch})";
        $inRepo = $next->repo === '' ? '' : " in {$next->repo}";
        fprintf(STDERR, "Tracking %s%s%s as %s\n", $next->issueId, $onBranch, $inRepo, $matched);
    }

    private function cmdEnd(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->endOpen(date('Y-m-d H:i'), 'ended', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('end: no open tracking entry');
        }
        $endedAt = $item->endedAt ?? '';
        fprintf(
            STDERR,
            "Stopped %s after %s\n",
            $item->issueId,
            Format::duration($item->startedAt, $endedAt),
        );
        if ($item->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $item->comment);
        }
    }

    private function cmdPause(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->endOpen(date('Y-m-d H:i'), 'paused', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('pause: no open tracking entry');
        }
        $endedAt = $item->endedAt ?? '';
        fprintf(
            STDERR,
            "Paused %s after %s\n",
            $item->issueId,
            Format::duration($item->startedAt, $endedAt),
        );
        if ($item->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $item->comment);
        }
    }

    private function cmdResume(?string $comment): void
    {
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null) {
            throw new RuntimeException('resume: no record to resume');
        }
        if ($last->isOpen()) {
            throw new RuntimeException('resume: a record is already open');
        }
        $now = date('Y-m-d H:i');
        $next = new Record(
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
        $store->track($next, 'resumed');
        $onBranch = $next->branch === null ? '' : " ({$next->branch})";
        fprintf(STDERR, "Resumed %s%s as %s\n", $next->issueId, $onBranch, $next->type);
        if ($next->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $next->comment);
        }
    }

    private function cmdSkip(?string $offset): void
    {
        $offsetMin = Resolver::parseOffset('skip', $offset);

        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('skip: no open tracking entry');
        }

        $nowDt = new DateTimeImmutable();
        $endDt = $nowDt->modify("-{$offsetMin} minutes");
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
        $store->save($items);

        fprintf(
            STDERR,
            "Skipped %s of %s: ended at %s, restarted at %s\n",
            Format::duration($endedAt, $now),
            $last->issueId,
            $endedAt,
            $now,
        );
    }

    private function cmdSteal(?string $issue, ?string $offset, ?string $type): void
    {
        $issueId = Resolver::requireIssueId('steal', $issue);
        $offsetMin = Resolver::parseOffset('steal', $offset);
        $resolvedType = Resolver::resolveType('steal', $type, self::TRACK_DEFAULT_TYPE, $this->loadOrFetchTypes(...));

        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('steal: no open tracking entry');
        }

        $nowDt = new DateTimeImmutable();
        $splitDt = $nowDt->modify("-{$offsetMin} minutes");
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
        $store->save($items);

        fprintf(
            STDERR,
            "Stole %s of %s (%s) from %s between %s and %s\n",
            Format::duration($splitAt, $now),
            $issueId,
            $resolvedType,
            $last->issueId,
            substr($splitAt, 11),
            substr($now, 11),
        );
    }

    private function cmdComment(?string $comment): void
    {
        if ($comment === null || $comment === '') {
            throw new RuntimeException('comment: missing <text>');
        }
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->commentOpen($comment);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('comment: no open tracking entry');
        }
        if (!$result['changed']) {
            return;
        }
        fprintf(STDERR, "Comment on %s: %s\n", $item->issueId, $item->comment);
    }

    private function cmdTrack(?string $issue, ?string $type): void
    {
        $issueId = Resolver::requireIssueId('track', $issue);
        $resolvedType = Resolver::resolveType('track', $type, self::TRACK_DEFAULT_TYPE, $this->loadOrFetchTypes(...));
        $this->startRecord($issueId, null, '', $resolvedType, 'manual');
    }

    private function cmdDay(?string $issue, ?string $date, ?string $type): void
    {
        $issueId = Resolver::requireIssueId('day', $issue);
        $when = Resolver::resolveDate($date);
        $resolvedType = Resolver::resolveType('day', $type, self::DAY_DEFAULT_TYPE, $this->loadOrFetchTypes(...));
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $dayKey = $when->format('Y-m-d');
        foreach ($store->load() as $existing) {
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
        $now = date('Y-m-d H:i');
        $record = new Record(
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
        $store->appendClosed($record);
        fprintf(
            STDERR,
            "Logged %s for %s as %s (8h)\n",
            $issueId,
            $dayKey,
            $resolvedType,
        );
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
        $now = date('Y-m-d H:i');
        $next = new Record(
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
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->track($next, $trigger);
        if (!$result['started']) {
            return;
        }
        $stopped = $result['stopped'];
        if ($stopped !== null && $stopped->endedAt !== null) {
            fprintf(
                STDERR,
                "Stopped %s after %s\n",
                $stopped->issueId,
                Format::duration($stopped->startedAt, $stopped->endedAt),
            );
        }
        $onBranch = $branch === null ? '' : " ({$branch})";
        $inRepo = $repo === '' ? '' : " in {$repo}";
        fprintf(STDERR, "Tracking %s%s%s as %s\n", $next->issueId, $onBranch, $inRepo, $resolvedType);
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
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();

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
            $newDt = $existingDt->modify("{$sign}{$offsetMin} minutes");
        }
        $newValue = $newDt->format('Y-m-d H:i');

        if ($newValue === $existing) {
            fwrite(STDERR, "No change.\n");

            return;
        }

        $modifiedAt = date('Y-m-d H:i');
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
        fwrite(STDERR, Ansi::lwhite('Old:') . "\n");
        for ($i = $firstShownIndex; $i <= $targetIndex; $i++) {
            fwrite(STDERR, $this->recordLine($items[$i]) . "\n");
        }
        fwrite(STDERR, "\n" . Ansi::lwhite('New:') . "\n");
        for ($i = $firstShownIndex; $i <= $targetIndex; $i++) {
            fwrite(STDERR, $this->recordLine($newItems[$i]) . "\n");
        }
        fwrite(STDERR, "\n");

        if (!$this->confirm('Apply?')) {
            fwrite(STDERR, "Cancelled.\n");

            return;
        }

        $store->save(array_values($newItems));
        fwrite(STDERR, "Saved.\n");
    }

    private function recordLine(Record $r): string
    {
        $start = substr($r->startedAt, 11);
        if ($r->endedAt === null) {
            $now = date('Y-m-d H:i');
            $duration = Format::duration($r->startedAt, $now) . ' so far';
            $end = '  ...';
        } else {
            $duration = Format::duration($r->startedAt, $r->endedAt);
            $end = substr($r->endedAt, 11);
        }

        return sprintf(
            '  %-9s  %-22s  %s → %-5s  (%s)',
            $r->issueId,
            $r->type,
            $start,
            $end,
            $duration,
        );
    }

    private function confirm(string $question): bool
    {
        fwrite(STDERR, "{$question} [y/N]: ");
        $line = fgets(STDIN);
        if ($line === false) {
            return false;
        }

        return in_array(strtolower(trim($line)), ['y', 'yes'], true);
    }

    private function cmdConfigure(): void
    {
        $configPath = $this->rootDir . self::CONFIG_FILE;
        $secretsPath = $this->rootDir . self::SECRETS_FILE;

        $existingBaseUrl = '';
        $existingTimezone = '';
        if (is_file($configPath)) {
            $cfg = $this->readNeonFile($configPath);
            $bu = $cfg['youtrackBaseUrl'] ?? null;
            if (is_string($bu)) {
                $existingBaseUrl = $bu;
            }
            $tz = $cfg['timezone'] ?? null;
            if (is_string($tz)) {
                $existingTimezone = $tz;
            }
        }

        $existingToken = '';
        if (is_file($secretsPath)) {
            $secrets = $this->readNeonFile($secretsPath);
            $tok = $secrets['youtrackToken'] ?? null;
            if (is_string($tok)) {
                $existingToken = $tok;
            }
        }

        fwrite(STDERR, Ansi::lwhite('Configuring timeshit') . "\n\n");

        $baseUrl = $this->prompt('YouTrack base URL', $existingBaseUrl);
        if ($baseUrl === '') {
            throw new RuntimeException('configure: YouTrack base URL is required');
        }

        $timezone = $this->prompt('Timezone', $existingTimezone === '' ? self::DEFAULT_TIMEZONE : $existingTimezone);
        if ($timezone === '') {
            throw new RuntimeException('configure: timezone is required');
        }

        $tokenHint = $existingToken === '' ? '' : ' [press Enter to keep existing]';
        fwrite(STDERR, "YouTrack token{$tokenHint}: ");
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('configure: failed to read input');
        }
        $token = trim($line);
        if ($token === '') {
            $token = $existingToken;
        }
        if ($token === '') {
            throw new RuntimeException('configure: YouTrack token is required');
        }

        $configDir = dirname($configPath);
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            throw new RuntimeException("configure: failed to create {$configDir}");
        }

        $configContents = Neon::encode(
            ['youtrackBaseUrl' => $baseUrl, 'timezone' => $timezone],
            Neon::BLOCK,
        );
        if (file_put_contents($configPath, $configContents) === false) {
            throw new RuntimeException("configure: failed to write {$configPath}");
        }
        fprintf(STDERR, "\nWrote %s\n", $configPath);

        $secretsContents = Neon::encode(
            ['youtrackToken' => $token],
            Neon::BLOCK,
        );
        if (file_put_contents($secretsPath, $secretsContents) === false) {
            throw new RuntimeException("configure: failed to write {$secretsPath}");
        }
        fprintf(STDERR, "Wrote %s\n", $secretsPath);
    }

    private function prompt(string $label, string $default): string
    {
        $hint = $default === '' ? '' : " [{$default}]";
        fwrite(STDERR, "{$label}{$hint}: ");
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('configure: failed to read input');
        }
        $trimmed = trim($line);

        return $trimmed === '' ? $default : $trimmed;
    }

    /** @return array<string, mixed> */
    private function readNeonFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = Neon::decode($raw);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<WorkItemType> */
    private function loadOrFetchTypes(): array
    {
        $cache = new WorkItemTypeCache($this->rootDir . self::WORK_ITEM_TYPES_FILE);
        if ($cache->isFresh()) {
            return $cache->load();
        }
        $this->fetchAndCacheTypes(Config::load($this->rootDir));

        return $cache->load();
    }

    private function fetchAndCacheTypes(Config $config): void
    {
        $cache = new WorkItemTypeCache($this->rootDir . self::WORK_ITEM_TYPES_FILE);
        $client = new YoutrackClient($config->youtrackBaseUrl, $config->youtrackToken);
        $types = $client->fetchWorkItemTypes();
        $cache->save($types);
        fprintf(STDERR, "Cached %d work item types\n", count($types));
    }

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    private function loadOrFetch(Config $config): array
    {
        $issueCache = new IssueCache($this->rootDir . self::ISSUES_CACHE_FILE);
        $workItemCache = new WorkItemCache($this->rootDir . self::WORK_ITEMS_CACHE_FILE);
        if ($issueCache->isFresh() && $workItemCache->isFresh()) {
            $issuesData = $issueCache->load();
            $workItemsData = $workItemCache->load();
            fprintf(
                STDERR,
                "Loaded %d issues and %d work items from cache (use 'refresh' to force update)\n\n",
                count($issuesData['issues']),
                count($workItemsData['items']),
            );

            return [
                'user' => $issuesData['user'],
                'issues' => $issuesData['issues'],
                'workItems' => $workItemsData['items'],
            ];
        }

        return $this->fetchAndCache($config);
    }

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    private function fetchAndCache(Config $config): array
    {
        $issueCache = new IssueCache($this->rootDir . self::ISSUES_CACHE_FILE);
        $workItemCache = new WorkItemCache($this->rootDir . self::WORK_ITEMS_CACHE_FILE);
        $client = new YoutrackClient($config->youtrackBaseUrl, $config->youtrackToken);

        $me = $client->me();
        fprintf(
            STDERR,
            "Connected to %s as %s (%s)\n",
            $config->youtrackBaseUrl,
            $me['fullName'],
            $me['login'],
        );

        $data = $client->fetchMine();
        $issueCache->save($me['login'], $data['issues']);
        $workItemCache->save($me['login'], $data['workItems']);
        fprintf(
            STDERR,
            "Cached %d issues and %d work items\n",
            count($data['issues']),
            count($data['workItems']),
        );

        return [
            'user' => $me['login'],
            'issues' => $data['issues'],
            'workItems' => $data['workItems'],
        ];
    }

}

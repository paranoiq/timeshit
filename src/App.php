<?php declare(strict_types=1);

namespace Timeshit;

use Collator;
use DateTimeImmutable;
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

use function count;
use function date;
use function date_default_timezone_set;
use function file_exists;
use function fprintf;
use function fwrite;
use function in_array;
use function max;
use function str_repeat;
use function usort;

use const STDERR;

final class App
{

    private const ISSUES_CACHE_FILE = '/data/issues.neon';
    private const WORK_ITEMS_CACHE_FILE = '/data/work-items.neon';
    private const WORK_ITEM_TYPES_FILE = '/data/work-item-types.neon';
    private const RECORDS_FILE = '/data/records.neon';

    private const TRACK_DEFAULT_TYPE = 'Implementation';
    private const DAY_DEFAULT_TYPE = 'Out of office';

    public function __construct(private readonly string $rootDir) {}

    private const COMMAND_NAMES = [
        'issues', 'work', 'records', 'types',
        'track', 'day', 'type', 'switch',
        'pause', 'resume', 'end', 'comment', 'refresh',
        'checkout',
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
                'end' => $this->cmdEnd(Resolver::restArgs($argv)),
                'comment' => $this->cmdComment(Resolver::restArgs($argv)),
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
            'Actions' => [
                [$cmd('track'),    $req('issue') . ' ' . $opt('type'),
                    "Manually switch local time tracking to " . $req('issue')
                    . " (type defaults to \"" . Ansi::lgreen(self::TRACK_DEFAULT_TYPE) . "\")"],
                [$cmd('day'),      $req('issue') . ' ' . $opt('date') . ' ' . $opt('type'),
                    "Log a full 8h day (date default to today; accepts day-of-month int, "
                    . Ansi::lgreen('y|yes|yesterday') . " etc.; type defaults to \"" . Ansi::lyellow(self::DAY_DEFAULT_TYPE) . '"'],
                [$cmd('type'),     $req('type'), 'Change the type of the currently tracked entry'],
                [$cmd('switch'),   $req('type'), 'End the current entry and open a new one with the same issue/branch/repo and the given ' . $req('type')],
                [$cmd('comment'),  $req('comment'), 'Set a comment on the currently tracked entry'],
                [$cmd('pause'),    $opt('comment'), 'Pause the currently tracked record (optional ' . $req('comment') . ' attached to the record)'],
                [$cmd('resume'),   $opt('comment'), 'Resume tracking from the most recent record (optional ' . $req('comment') . ' on the new record)'],
                [$cmd('end'),      $opt('comment'), 'End the currently tracked entry (optional ' . $req('comment') . ' attached to the record)'],
                [$cmd('refresh'),  '', 'Force-refresh all caches from YouTrack and print stats'],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'),
                    "Switch tracking on git checkout (called from " . $cmd('hooks/post-checkout') . ")"],
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

        echo Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + Git +the  GitLab') . "\n\n";
        echo "Usage: " . $cmd('timeshit.php') . " " . $req('command') . " " . $opt('args') . "\n";
        foreach ($groups as $title => $rows) {
            echo "\n" . Ansi::lwhite($title) . ":\n";
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

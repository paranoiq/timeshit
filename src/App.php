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

use function array_slice;
use function count;
use function date;
use function file_exists;
use function fprintf;
use function fwrite;
use function implode;
use function intdiv;
use function max;
use function preg_match;
use function str_repeat;
use function mb_strtolower;
use function strtoupper;
use function usort;

use const STDERR;

final class App
{

    private const ISSUES_CACHE_FILE = '/data/issues.json';
    private const WORK_ITEMS_CACHE_FILE = '/data/work-items.json';
    private const WORK_ITEM_TYPES_FILE = '/data/work-item-types.json';
    private const RECORDS_FILE = '/data/records.json';

    private const TRACK_DEFAULT_TYPE = 'Implementation';

    public function __construct(private readonly string $rootDir) {}

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;

        if ($command === null || $command === 'help' || $command === '-h' || $command === '--help') {
            $this->printHelp();

            return 0;
        }

        try {
            match ($command) {
                'issues' => $this->cmdIssues(Config::load($this->rootDir)),
                'work' => $this->cmdWork(Config::load($this->rootDir)),
                'records' => $this->cmdRecords(Config::load($this->rootDir)),
                'refresh' => $this->cmdRefresh(Config::load($this->rootDir)),
                'track' => $this->cmdTrack($argv[2] ?? null, $argv[3] ?? null),
                'checkout' => $this->cmdCheckout($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'type' => $this->cmdType($argv[2] ?? null),
                'types' => $this->cmdTypes(),
                'switch' => $this->cmdSwitch($argv[2] ?? null),
                'end' => $this->cmdEnd(self::restArgs($argv)),
                'comment' => $this->cmdComment(self::restArgs($argv)),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");

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

    private function printHelp(): void
    {
        $cmd = static fn(string $name): string => Ansi::lgreen($name);
        $req = static fn(string $name): string => Ansi::yellow("<{$name}>");
        $opt = static fn(string $name): string => Ansi::lblack("[") . Ansi::yellow("<{$name}>") . Ansi::lblack("]");

        $rows = [
            [$cmd('issues'),   '', 'List YouTrack issues you are involved in (cached for 24h)'],
            [$cmd('work'),     '', 'List your YouTrack work items grouped by week and day (cached for 24h)'],
            [$cmd('records'),  '', 'List locally tracked records not yet synced to YouTrack'],
            [$cmd('types'),    '', 'List the YouTrack work-item types (cached for 24h)'],
            [$cmd('refresh'),  '', 'Force-refresh the caches and list issues'],
            [$cmd('track'),    $req('branch') . ' ' . $opt('type'),
                "Manually switch local time tracking to " . $req('branch')
                . " (type defaults to " . Ansi::lgreen(self::TRACK_DEFAULT_TYPE) . ")"],
            [$cmd('checkout'), $req('branch') . ' ' . $req('repo') . ' ' . $opt('type'),
                "Switch tracking on git checkout (called from " . $cmd('hooks/post-checkout') . ")"],
            [$cmd('type'),     $req('type'), 'Change the type of the currently tracked entry'],
            [$cmd('switch'),   $req('type'), 'End the current entry and open a new one with the same issue/branch/repo and the given ' . $req('type')],
            [$cmd('end'),      $opt('comment'), 'End the currently tracked entry (optional ' . $req('comment') . ' attached to the record)'],
            [$cmd('comment'),  $req('text'), 'Set a comment on the currently tracked entry'],
        ];

        $nameWidth = 0;
        $argsWidth = 0;
        foreach ($rows as [$name, $args]) {
            $nameWidth = max($nameWidth, Ansi::length($name));
            $argsWidth = max($argsWidth, Ansi::length($args));
        }

        echo Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + Git +the  GitLab') . "\n\n";
        echo "Usage: " . $cmd('timeshit.php') . " " . $req('command') . " " . $opt('args') . "\n\n";
        echo "Commands:\n";
        foreach ($rows as [$name, $args, $desc]) {
            echo "  " . $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2)
                . $args . str_repeat(' ', $argsWidth - Ansi::length($args) + 2)
                . $desc . "\n";
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
            echo $t->name . str_repeat(' ', $nameWidth - Ansi::length($t->name) + 2) . Ansi::lblack($t->id) . "\n";
        }
    }

    private function cmdRefresh(Config $config): void
    {
        $data = $this->fetchAndCache($config);
        $this->fetchAndCacheTypes($config);
        (new IssuesView($config->youtrackBaseUrl, $data['user']))->render($data['issues'], $data['workItems']);
    }

    private function cmdType(?string $newType): void
    {
        if ($newType === null || $newType === '') {
            throw new RuntimeException('type: missing <type>');
        }
        $types = $this->loadOrFetchTypes();
        $matched = self::matchType($newType, $types);
        if ($matched === null) {
            $names = [];
            foreach ($types as $t) {
                $names[] = $t->name;
            }
            throw new RuntimeException("type: unknown type '{$newType}'. Known: " . implode(', ', $names));
        }
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
        if ($newType === null || $newType === '') {
            throw new RuntimeException('switch: missing <type>');
        }
        $types = $this->loadOrFetchTypes();
        $matched = self::matchType($newType, $types);
        if ($matched === null) {
            $names = [];
            foreach ($types as $t) {
                $names[] = $t->name;
            }
            throw new RuntimeException("switch: unknown type '{$newType}'. Known: " . implode(', ', $names));
        }
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('switch: no open tracking entry');
        }
        $trigger = 'ts switch';
        $next = new Record(
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $matched,
            startedAt: date('c'),
            startTrigger: $trigger,
            endedAt: null,
            endTrigger: null,
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
                self::formatDuration($stopped->startedAt, $stopped->endedAt),
            );
        }
        fprintf(STDERR, "Tracking %s (%s) in %s as %s\n", $next->issueId, $next->branch, $next->repo, $matched);
    }

    private function cmdEnd(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->endOpen(date('c'), 'ts end', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('end: no open tracking entry');
        }
        $endedAt = $item->endedAt ?? '';
        fprintf(
            STDERR,
            "Stopped %s after %s\n",
            $item->issueId,
            self::formatDuration($item->startedAt, $endedAt),
        );
        if ($item->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $item->comment);
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

    private function cmdTrack(?string $branch, ?string $type): void
    {
        $this->startRecord('track', $branch, '', $type, 'manual');
    }

    private function cmdCheckout(?string $branch, ?string $repo, ?string $type): void
    {
        if ($repo === null || $repo === '') {
            throw new RuntimeException('checkout: missing <repo>');
        }
        $this->startRecord('checkout', $branch, $repo, $type, 'checkout');
    }

    private function startRecord(string $cmd, ?string $branch, string $repo, ?string $type, string $trigger): void
    {
        if ($branch === null || $branch === '') {
            throw new RuntimeException("{$cmd}: missing <branch>");
        }
        $resolvedType = $type === null || $type === '' ? self::TRACK_DEFAULT_TYPE : $type;
        $next = new Record(
            issueId: self::extractIssueId($branch),
            branch: $branch,
            repo: $repo,
            type: $resolvedType,
            startedAt: date('c'),
            startTrigger: $trigger,
            endedAt: null,
            endTrigger: null,
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
                self::formatDuration($stopped->startedAt, $stopped->endedAt),
            );
        }
        $inRepo = $repo === '' ? '' : " in {$repo}";
        fprintf(STDERR, "Tracking %s (%s)%s as %s\n", $next->issueId, $branch, $inRepo, $resolvedType);
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

    /** @param array<int, string> $argv */
    private static function restArgs(array $argv): ?string
    {
        $rest = array_slice($argv, 2);
        if ($rest === []) {
            return null;
        }

        return implode(' ', $rest);
    }

    /** @param list<WorkItemType> $types */
    private static function matchType(string $input, array $types): ?string
    {
        $needle = mb_strtolower($input);
        foreach ($types as $type) {
            if (mb_strtolower($type->name) === $needle) {
                return $type->name;
            }
        }

        return null;
    }

    private static function extractIssueId(string $branch): string
    {
        if (preg_match('/^([A-Za-z]{1,3}-\d+)\b/', $branch, $m) === 1) {
            return strtoupper($m[1]);
        }

        return $branch;
    }

    private static function formatDuration(string $startedAt, string $endedAt): string
    {
        $start = new DateTimeImmutable($startedAt);
        $end = new DateTimeImmutable($endedAt);
        $minutes = max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins === 0 ? "{$hours}h" : "{$hours}h {$mins}m";
    }
}
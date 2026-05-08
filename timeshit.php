<?php declare(strict_types=1);

require __DIR__ . '/src/Ansi.php';
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Issue.php';
require __DIR__ . '/src/WorkItem.php';
require __DIR__ . '/src/WorkLocalItem.php';
require __DIR__ . '/src/WorkItemType.php';
require __DIR__ . '/src/IssueCache.php';
require __DIR__ . '/src/WorkItemCache.php';
require __DIR__ . '/src/WorkItemTypeCache.php';
require __DIR__ . '/src/WorkLocalStore.php';
require __DIR__ . '/src/Format.php';
require __DIR__ . '/src/IssuesView.php';
require __DIR__ . '/src/WorkView.php';
require __DIR__ . '/src/YoutrackClient.php';

use Timeshit\Ansi;
use Timeshit\Config;
use Timeshit\Issue;
use Timeshit\IssueCache;
use Timeshit\IssuesView;
use Timeshit\WorkItem;
use Timeshit\WorkItemCache;
use Timeshit\WorkItemType;
use Timeshit\WorkItemTypeCache;
use Timeshit\WorkLocalItem;
use Timeshit\WorkLocalStore;
use Timeshit\WorkView;
use Timeshit\YoutrackClient;

const ISSUES_CACHE_PATH = __DIR__ . '/data/issues.json';
const WORK_ITEMS_CACHE_PATH = __DIR__ . '/data/work-items.json';
const WORK_ITEM_TYPES_PATH = __DIR__ . '/data/work-item-types.json';
const WORK_LOCAL_PATH = __DIR__ . '/data/work-local.json';
const TRACK_DEFAULT_TYPE = 'Implementation';

$command = $argv[1] ?? null;

if ($command === null || $command === 'help' || $command === '-h' || $command === '--help') {
    printHelp();
    exit(0);
}

try {
    match ($command) {
        'issues' => cmdIssues(Config::load(__DIR__)),
        'work' => cmdWork(Config::load(__DIR__)),
        'refresh' => cmdRefresh(Config::load(__DIR__)),
        'track' => cmdTrack($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
        'type' => cmdType($argv[2] ?? null),
        'switch' => cmdSwitch($argv[2] ?? null),
        'end' => cmdEnd($argv[2] ?? null),
        'comment' => cmdComment($argv[2] ?? null),
        default => unknownCommand($command),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

function unknownCommand(string $command): never
{
    fwrite(STDERR, Ansi::red("Unknown command: {$command}") . "\n\n");
    printHelp();
    exit(1);
}

function printHelp(): void
{
    $cmd = static fn(string $name): string => Ansi::lgreen($name);
    $req = static fn(string $name): string => Ansi::yellow("<{$name}>");
    $opt = static fn(string $name): string => Ansi::lblack("[") . Ansi::yellow("<{$name}>") . Ansi::lblack("]");

    $rows = [
        [$cmd('issues'),   '', 'List YouTrack issues you are involved in (cached for 24h)'],
        [$cmd('work'),     '', 'List your work items grouped by week and day (cached for 24h)'],
        [$cmd('refresh'),  '', 'Force-refresh the caches and list issues'],
        [$cmd('track'),    $req('branch') . ' ' . $req('repo') . ' ' . $opt('type'),
             "Switch local time tracking to " . $req('branch') . " in " . $req('repo')
             . " (type defaults to " . Ansi::lgreen(TRACK_DEFAULT_TYPE) . ")"],
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

    echo Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + GitLab') . "\n\n";
    echo "Usage: " . $cmd('timeshit.php') . " " . $req('command') . " " . $opt('args') . "\n\n";
    echo "Commands:\n";
    foreach ($rows as [$name, $args, $desc]) {
        echo "  " . $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2)
            . $args . str_repeat(' ', $argsWidth - Ansi::length($args) + 2)
            . $desc . "\n";
    }
}

function cmdIssues(Config $config): void
{
    $data = loadOrFetch($config);
    (new IssuesView($config->youtrackBaseUrl, $data['user']))->render($data['issues'], $data['workItems']);
}

function cmdWork(Config $config): void
{
    $data = loadOrFetch($config);
    (new WorkView($config->youtrackBaseUrl))->render($data['workItems'], $data['issues']);
}

function cmdRefresh(Config $config): void
{
    $data = fetchAndCache($config);
    fetchAndCacheTypes($config);
    (new IssuesView($config->youtrackBaseUrl, $data['user']))->render($data['issues'], $data['workItems']);
}

function cmdType(?string $newType): void
{
    if ($newType === null || $newType === '') {
        throw new RuntimeException('type: missing <type>');
    }
    $types = loadOrFetchTypes();
    $matched = matchType($newType, $types);
    if ($matched === null) {
        $names = [];
        foreach ($types as $t) {
            $names[] = $t->name;
        }
        throw new RuntimeException("type: unknown type '{$newType}'. Known: " . implode(', ', $names));
    }
    $result = (new WorkLocalStore(WORK_LOCAL_PATH))->changeOpenType($matched);
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

function cmdSwitch(?string $newType): void
{
    if ($newType === null || $newType === '') {
        throw new RuntimeException('switch: missing <type>');
    }
    $types = loadOrFetchTypes();
    $matched = matchType($newType, $types);
    if ($matched === null) {
        $names = [];
        foreach ($types as $t) {
            $names[] = $t->name;
        }
        throw new RuntimeException("switch: unknown type '{$newType}'. Known: " . implode(', ', $names));
    }
    $store = new WorkLocalStore(WORK_LOCAL_PATH);
    $items = $store->load();
    $last = $items === [] ? null : $items[count($items) - 1];
    if ($last === null || !$last->isOpen()) {
        throw new RuntimeException('switch: no open tracking entry');
    }
    $trigger = 'ts switch';
    $next = new WorkLocalItem(
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
            formatDuration($stopped->startedAt, $stopped->endedAt),
        );
    }
    fprintf(STDERR, "Tracking %s (%s) in %s as %s\n", $next->issueId, $next->branch, $next->repo, $matched);
}

function cmdEnd(?string $comment): void
{
    $resolved = $comment === '' ? null : $comment;
    $result = (new WorkLocalStore(WORK_LOCAL_PATH))->endOpen(date('c'), 'ts end', $resolved);
    $item = $result['item'];
    if ($item === null) {
        throw new RuntimeException('end: no open tracking entry');
    }
    $endedAt = $item->endedAt ?? '';
    fprintf(
        STDERR,
        "Stopped %s after %s\n",
        $item->issueId,
        formatDuration($item->startedAt, $endedAt),
    );
    if ($item->comment !== '') {
        fprintf(STDERR, "Comment: %s\n", $item->comment);
    }
}

function cmdComment(?string $comment): void
{
    if ($comment === null || $comment === '') {
        throw new RuntimeException('comment: missing <text>');
    }
    $result = (new WorkLocalStore(WORK_LOCAL_PATH))->commentOpen($comment);
    $item = $result['item'];
    if ($item === null) {
        throw new RuntimeException('comment: no open tracking entry');
    }
    if (!$result['changed']) {
        return;
    }
    fprintf(STDERR, "Comment on %s: %s\n", $item->issueId, $item->comment);
}

/** @return list<WorkItemType> */
function loadOrFetchTypes(): array
{
    $cache = new WorkItemTypeCache(WORK_ITEM_TYPES_PATH);
    if ($cache->isFresh()) {
        return $cache->load();
    }
    fetchAndCacheTypes(Config::load(__DIR__));

    return $cache->load();
}

function fetchAndCacheTypes(Config $config): void
{
    $cache = new WorkItemTypeCache(WORK_ITEM_TYPES_PATH);
    $client = new YoutrackClient($config->youtrackBaseUrl, $config->youtrackToken);
    $types = $client->fetchWorkItemTypes();
    $cache->save($types);
    fprintf(STDERR, "Cached %d work item types\n", count($types));
}

/** @param list<WorkItemType> $types */
function matchType(string $input, array $types): ?string
{
    $needle = strtolower($input);
    foreach ($types as $type) {
        if (strtolower($type->name) === $needle) {
            return $type->name;
        }
    }

    return null;
}

function cmdTrack(?string $branch, ?string $repo, ?string $type): void
{
    if ($branch === null || $branch === '') {
        throw new RuntimeException('track: missing <branch>');
    }
    if ($repo === null || $repo === '') {
        throw new RuntimeException('track: missing <repo>');
    }
    $resolvedType = $type === null || $type === '' ? TRACK_DEFAULT_TYPE : $type;
    $trigger = 'git checkout';
    $next = new WorkLocalItem(
        issueId: extractIssueId($branch),
        branch: $branch,
        repo: $repo,
        type: $resolvedType,
        startedAt: date('c'),
        startTrigger: $trigger,
        endedAt: null,
        endTrigger: null,
    );
    $result = (new WorkLocalStore(WORK_LOCAL_PATH))->track($next, $trigger);
    if (!$result['started']) {
        return;
    }
    $stopped = $result['stopped'];
    if ($stopped !== null && $stopped->endedAt !== null) {
        fprintf(
            STDERR,
            "Stopped %s after %s\n",
            $stopped->issueId,
            formatDuration($stopped->startedAt, $stopped->endedAt),
        );
    }
    fprintf(STDERR, "Tracking %s (%s) in %s as %s\n", $next->issueId, $branch, $repo, $resolvedType);
}

function extractIssueId(string $branch): string
{
    if (preg_match('/^([A-Za-z]{1,3}-\d+)\b/', $branch, $m) === 1) {
        return strtoupper($m[1]);
    }

    return $branch;
}

function formatDuration(string $startedAt, string $endedAt): string
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

/** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
function loadOrFetch(Config $config): array
{
    $issueCache = new IssueCache(ISSUES_CACHE_PATH);
    $workItemCache = new WorkItemCache(WORK_ITEMS_CACHE_PATH);
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

    return fetchAndCache($config);
}

/** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
function fetchAndCache(Config $config): array
{
    $issueCache = new IssueCache(ISSUES_CACHE_PATH);
    $workItemCache = new WorkItemCache(WORK_ITEMS_CACHE_PATH);
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
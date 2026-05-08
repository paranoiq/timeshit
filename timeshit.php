<?php declare(strict_types=1);

require __DIR__ . '/src/Ansi.php';
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Issue.php';
require __DIR__ . '/src/WorkItem.php';
require __DIR__ . '/src/IssueCache.php';
require __DIR__ . '/src/WorkItemCache.php';
require __DIR__ . '/src/YoutrackClient.php';

use Timeshit\Ansi;
use Timeshit\Config;
use Timeshit\IssueCache;
use Timeshit\WorkItem;
use Timeshit\WorkItemCache;
use Timeshit\YoutrackClient;
use Timeshit\Issue;

const ISSUES_CACHE_PATH = __DIR__ . '/data/issues.json';
const WORK_ITEMS_CACHE_PATH = __DIR__ . '/data/work-items.json';

$command = $argv[1] ?? null;

if ($command === null || $command === 'help' || $command === '-h' || $command === '--help') {
    echo "Usage: timeshit.php <command>\n\nCommands:\n"
        . "  issues    List YouTrack issues you are involved in (cached for 24h)\n"
        . "  refresh   Force-refresh the issues cache and list them\n";
    exit($command === null ? 1 : 0);
}

try {
    $config = Config::load(__DIR__);
    match ($command) {
        'issues' => cmdIssues($config),
        'refresh' => cmdRefresh($config),
        default => throw new RuntimeException("Unknown command: $command"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

function cmdIssues(Config $config): void
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
    } else {
        $fresh = fetchAndCache($config, $issueCache, $workItemCache);
        $issuesData = $fresh['issues'];
    }
    printIssues($issuesData['issues'], $issuesData['user']);
}

function cmdRefresh(Config $config): void
{
    $issueCache = new IssueCache(ISSUES_CACHE_PATH);
    $workItemCache = new WorkItemCache(WORK_ITEMS_CACHE_PATH);
    $fresh = fetchAndCache($config, $issueCache, $workItemCache);
    printIssues($fresh['issues']['issues'], $fresh['issues']['user']);
}

/**
 * @return array{
 *     issues: array{user: string, issues: list<Issue>},
 *     workItems: array{user: string, items: list<WorkItem>},
 * }
 */
function fetchAndCache(Config $config, IssueCache $issueCache, WorkItemCache $workItemCache): array
{
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
        'issues' => ['user' => $me['login'], 'issues' => $data['issues']],
        'workItems' => ['user' => $me['login'], 'items' => $data['workItems']],
    ];
}

/** @param list<Issue> $issues */
function printIssues(array $issues, string $currentUser): void
{
    usort(
        $issues,
        static fn(Issue $a, Issue $b): int => statePriority($a->state) <=> statePriority($b->state),
    );

    echo 'ROLES: a' . Ansi::lblack('=assignee')
        . '  c' . Ansi::lblack('=commenter')
        . '  r' . Ansi::lblack('=reporter')
        . '  u' . Ansi::lblack('=updater')
        . '  w' . Ansi::lblack('=work author')
        . "\n\n";

    $format = "%-10s %-10s %-8s %-18s %-6s %-20s %-11s %s\n";
    printf($format, 'ID', /*'PROJECT',*/'TYPE', 'CATEGORY', 'STATE', 'ROLES', 'ASSIGNEE', '   SPENT', 'TITLE');
    printf(
        $format,
        str_repeat('-', 10),
        //str_repeat('-', 8),
        str_repeat('-', 10),
        str_repeat('-', 8),
        str_repeat('-', 18),
        str_repeat('-', 6),
        str_repeat('-', 20),
        str_repeat('-', 11),
        str_repeat('-', 50),
    );
    foreach ($issues as $issue) {
        printf(
            $format,
            $issue->id,
            //$issue->project,
            $issue->type,
            formatCategory($issue->category),
            colorizeState($issue->state),
            formatRoles($issue->roles),
            colorizeAssignee($issue->assignee, $currentUser),
            formatSpent($issue->spent),
            $issue->title,
        );
    }
}

function formatCategory(string $category): string
{
    $shorts = [
        'Admin / Overhead / Support' => 'Admin',
        'Generic new feature' => 'Feature',
        'Internal tooling' => 'Tooling',
        'Technical debt' => 'Debt',
    ];

    return str_replace(array_keys($shorts), array_values($shorts), $category);
}

/** @param list<string> $roles */
function formatRoles(array $roles): string
{
    $order = [
        'assignee' => 'a',
        'commenter' => 'c',
        'reporter' => 'r',
        'updater' => 'u',
        'workAuthor' => 'w',
    ];
    $mask = '';
    foreach ($order as $role => $letter) {
        $mask .= in_array($role, $roles, true) ? $letter : Ansi::lblack('-');
    }

    return $mask . ' ';
}

function formatSpent(int $totalMinutes): string
{
    if ($totalMinutes === 0) {
        return sprintf('%11s', '-');
    }
    $minutes = $totalMinutes % 60;
    $totalHours = intdiv($totalMinutes, 60);
    $hours = $totalHours % 8;
    $days = intdiv($totalHours, 8);
    $daysPart = $days > 0 ? sprintf('%3d', $days) . Ansi::lblack('d') : '    ';
    $hoursPart = $hours > 0 ? sprintf('%d', $hours) . Ansi::lblack('h') : '  ';
    $minutesPart = $minutes > 0 ? sprintf('%2d', $minutes) . Ansi::lblack('m') : '   ';

    return $daysPart . ' ' . $hoursPart . ' ' . $minutesPart;
}

function colorizeAssignee(string $assignee, string $currentUser): string
{
    $padded = sprintf('%-20s', $assignee);

    return $assignee === $currentUser ? Ansi::lgreen($padded) : $padded;
}

function statePriority(string $state): int
{
    return match (true) {
        $state === 'Blocked' => 1,
        $state === 'In Progress' => 2,
        in_array($state, ['Code Review', 'Sprint Scheduled', 'To Verify'], true) => 3,
        in_array($state, ['Refinement', 'Reopened'], true) => 4,
        in_array($state, ['New', 'Submitted', 'Open'], true) => 5,
        in_array($state, ['Done', 'Solved', 'Closed', 'Merged', 'Released', 'Verified', "Won't Fix", 'Duplicate', 'Cancelled'], true) => 6,
        default => 0,
    };
}

function colorizeState(string $state): string
{
    $padded = sprintf('%-18s', $state);

    return match (true) {
        $state === 'Blocked' => Ansi::red($padded),
        $state === 'In Progress' => Ansi::yellow($padded),
        $state === 'Code Review' || $state === 'To Verify' => Ansi::cyan($padded),
        in_array($state, ['Done', 'Solved', 'Closed', 'Merged', 'Released', 'Verified', "Won't Fix", 'Duplicate', 'Cancelled'], true) => Ansi::lblack($padded),
        default => $padded,
    };
}
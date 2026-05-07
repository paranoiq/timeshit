<?php declare(strict_types=1);

require __DIR__ . '/src/Ansi.php';
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/YoutrackIssue.php';
require __DIR__ . '/src/IssueCache.php';
require __DIR__ . '/src/YoutrackClient.php';

use Timeshit\Ansi;
use Timeshit\Config;
use Timeshit\IssueCache;
use Timeshit\YoutrackClient;
use Timeshit\YoutrackIssue;

const CACHE_PATH = __DIR__ . '/data/issues.json';

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
    $cache = new IssueCache(CACHE_PATH);
    if ($cache->isFresh()) {
        $data = $cache->load();
        fprintf(STDERR, "Loaded %d issues from cache (use 'refresh' to force update)\n\n", count($data['issues']));
    } else {
        $data = fetchAndCacheIssues($config, $cache);
    }
    printIssues($data['issues'], $data['user']);
}

function cmdRefresh(Config $config): void
{
    $cache = new IssueCache(CACHE_PATH);
    $data = fetchAndCacheIssues($config, $cache);
    printIssues($data['issues'], $data['user']);
}

/** @return array{user: string, issues: list<YoutrackIssue>} */
function fetchAndCacheIssues(Config $config, IssueCache $cache): array
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

    $issues = $client->myIssues();
    $cache->save($me['login'], $issues);
    fprintf(STDERR, "Cached %d issues\n", count($issues));
    return ['user' => $me['login'], 'issues' => $issues];
}

/** @param list<YoutrackIssue> $issues */
function printIssues(array $issues, string $currentUser): void
{
    usort(
        $issues,
        static fn(YoutrackIssue $a, YoutrackIssue $b): int => statePriority($a->state) <=> statePriority($b->state),
    );

    $format = "%-10s %-8s %-18s %-10s %-20s %-20s %-10s %s\n";
    printf($format, 'ID', 'PROJECT', 'STATE', 'TYPE', 'CATEGORY', 'ASSIGNEE', '  SPENT', 'TITLE');
    printf(
        $format,
        str_repeat('-', 10),
        str_repeat('-', 8),
        str_repeat('-', 18),
        str_repeat('-', 10),
        str_repeat('-', 20),
        str_repeat('-', 20),
        str_repeat('-', 10),
        str_repeat('-', 50),
    );
    foreach ($issues as $issue) {
        printf(
            $format,
            $issue->id,
            $issue->project,
            colorizeState($issue->state),
            $issue->type,
            $issue->category,
            colorizeAssignee($issue->assignee, $currentUser),
            formatSpent($issue->spent),
            $issue->title,
        );
    }
}

function formatSpent(int $totalMinutes): string
{
    if ($totalMinutes === 0) {
        return sprintf('%10s', '-');
    }
    $minutes = $totalMinutes % 60;
    $totalHours = intdiv($totalMinutes, 60);
    $hours = $totalHours % 8;
    $days = intdiv($totalHours, 8);
    $daysPart = $days > 0 ? sprintf('%2d', $days) . Ansi::lblack('d') : '   ';
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
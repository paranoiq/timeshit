<?php declare(strict_types=1);

require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/YoutrackIssue.php';
require __DIR__ . '/src/YoutrackClient.php';

use Timeshit\Config;
use Timeshit\YoutrackClient;

$command = $argv[1] ?? null;

if ($command === null || $command === 'help' || $command === '-h' || $command === '--help') {
    echo "Usage: timeshit.php <command>\n\nCommands:\n"
        . "  issues   List YouTrack issues you are involved in\n";
    exit($command === null ? 1 : 0);
}

try {
    $config = Config::load(__DIR__);
    match ($command) {
        'issues' => cmdIssues($config),
        default => throw new RuntimeException("Unknown command: $command"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

function cmdIssues(Config $config): void
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

    $format = "%-10s %-8s %-18s %-10s %-20s %-25s %-12s %s\n";
    printf($format, 'ID', 'PROJECT', 'STATE', 'TYPE', 'CATEGORY', 'ASSIGNEE', 'SPENT', 'TITLE');
    foreach ($issues as $issue) {
        printf(
            $format,
            $issue->id,
            $issue->project,
            $issue->state,
            $issue->type,
            $issue->category,
            $issue->assignee,
            $issue->spent,
            $issue->title,
        );
    }
}
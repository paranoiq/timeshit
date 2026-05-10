<?php declare(strict_types=1);

namespace Timeshit\View;

use Timeshit\Format;
use Timeshit\Util\Ansi;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\WorkItem;
use function printf;
use function rtrim;
use function sprintf;
use function str_repeat;
use function usort;

final class IssuesView
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $currentUser,
    ) {}

    /**
     * @param list<Issue> $issues
     * @param list<WorkItem> $workItems
     */
    public function render(array $issues, array $workItems): void
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        usort(
            $issues,
            static fn(Issue $a, Issue $b): int => Format::statePriority($a->state) <=> Format::statePriority($b->state),
        );

        $mineByIssue = [];
        foreach ($workItems as $item) {
            $mineByIssue[$item->issueId] = ($mineByIssue[$item->issueId] ?? 0) + $item->minutes;
        }

        echo 'ROLES: a' . Ansi::lblack('=assignee')
            . '  c' . Ansi::lblack('=commenter')
            . '  r' . Ansi::lblack('=reporter')
            . '  u' . Ansi::lblack('=updater')
            . '  ' . Ansi::lgreen('w') . Ansi::lblack('=work author')
            . '  s' . Ansi::lblack('=starred')
            . '  m' . Ansi::lblack('=mentioned')
            . "\n\n";

        $format = "%-8s %-8s %-8s %-12s %-8s %-17s %-11s %-11s %s\n";
        printf($format, 'ID', 'TYPE', 'CAT.', 'STATE', 'ROLES', 'ASSIGNEE', '   SPENT', '    ALL', 'TITLE');
        printf(
            $format,
            str_repeat('-', 8),
            str_repeat('-', 8),
            str_repeat('-', 8),
            str_repeat('-', 12),
            str_repeat('-', 8),
            str_repeat('-', 17),
            str_repeat('-', 11),
            str_repeat('-', 11),
            str_repeat('-', 60),
        );
        foreach ($issues as $issue) {
            $url = $baseUrl . '/issue/' . $issue->id;
            $mine = $mineByIssue[$issue->id] ?? 0;
            printf(
                $format,
                Ansi::link($url, sprintf('%-8s', $issue->id)),
                $issue->type,
                Format::category($issue->category),
                Format::state($issue->state),
                Format::roles($issue->roles),
                Format::assignee($issue->assignee, $this->currentUser),
                Format::spent($mine, Ansi::lgreen(...)),
                Format::spent($issue->spent),
                Ansi::link($url, $issue->title),
            );
        }
    }
}

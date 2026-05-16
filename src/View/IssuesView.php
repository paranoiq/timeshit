<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\WorkItem;
use function intdiv;
use function max;
use function printf;
use function rtrim;
use function sprintf;
use function str_repeat;
use function time;
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
     * @param list<Record> $records local records (not yet synced to YouTrack) — added to the SPENT column
     */
    public function render(array $issues, array $workItems, array $records = []): void
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        $currentUser = $this->currentUser;
        $mineActive = static function (Issue $issue) use ($currentUser): bool {
            return $issue->assignee === $currentUser && Format::statePriority($issue->state) !== 7;
        };
        usort(
            $issues,
            static function (Issue $a, Issue $b) use ($mineActive): int {
                $aMine = $mineActive($a);
                $bMine = $mineActive($b);
                if ($aMine !== $bMine) {
                    return $aMine ? -1 : 1;
                }

                return Format::statePriority($a->state) <=> Format::statePriority($b->state);
            },
        );

        $mineByIssue = [];
        foreach ($workItems as $item) {
            $mineByIssue[$item->issueId] = ($mineByIssue[$item->issueId] ?? 0) + $item->minutes;
        }
        $now = time();
        foreach ($records as $record) {
            if ($record->status === 'untracked') {
                continue;
            }
            $start = (new DateTimeImmutable($record->startedAt))->getTimestamp();
            $end = $record->endedAt !== null
                ? (new DateTimeImmutable($record->endedAt))->getTimestamp()
                : $now;
            $mineByIssue[$record->issueId] = ($mineByIssue[$record->issueId] ?? 0)
                + max(0, intdiv($end - $start, 60));
        }

        echo 'ROLES: a' . Ansi::lblack('=assignee')
            . '  c' . Ansi::lblack('=commenter')
            . '  r' . Ansi::lblack('=reporter')
            . '  u' . Ansi::lblack('=updater')
            . '  ' . Ansi::lgreen('w') . Ansi::lblack('=work author')
            . '  s' . Ansi::lblack('=starred')
            . '  m' . Ansi::lblack('=mentioned')
            . "\n\n";

        $format = "%-8s %-8s %-11s %-12s %-8s %-17s %-11s %-11s %s\n";
        $printRule = static function () use ($format): void {
            printf(
                $format,
                str_repeat('-', 8),
                str_repeat('-', 8),
                str_repeat('-', 11),
                str_repeat('-', 12),
                str_repeat('-', 8),
                str_repeat('-', 17),
                str_repeat('-', 11),
                str_repeat('-', 11),
                str_repeat('-', 60),
            );
        };
        printf($format, 'ID', 'TYPE', 'CAT.', 'STATE', 'ROLES', 'ASSIGNEE', '   SPENT', '    ALL', 'TITLE');
        $printRule();
        $lastWasMine = false;
        $lastWasFinished = false;
        $afterMinePrinted = false;
        $beforeFinishedPrinted = false;
        $first = true;
        foreach ($issues as $issue) {
            $isMine = $mineActive($issue);
            $isFinished = Format::statePriority($issue->state) === 7;
            $needRule = false;
            if (!$first && $lastWasMine && !$isMine && !$afterMinePrinted) {
                $needRule = true;
                $afterMinePrinted = true;
            }
            if (!$first && !$lastWasFinished && $isFinished && !$beforeFinishedPrinted) {
                $needRule = true;
                $beforeFinishedPrinted = true;
            }
            if ($needRule) {
                $printRule();
            }
            $first = false;
            $lastWasMine = $isMine;
            $lastWasFinished = $isFinished;
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

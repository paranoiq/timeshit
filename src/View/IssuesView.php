<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\IssueState;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\WorkItem;
use function implode;
use function intdiv;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function printf;
use function rtrim;
use function sprintf;
use function str_repeat;
use function time;
use function usort;

final class IssuesView
{
    /**
     * @param array<string, IssueState> $states
     * @param array<string, string> $categoryColors canonical category name => Ansi color name
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $currentUser,
        private readonly array $states,
        private readonly array $categoryColors,
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
        $states = $this->states;
        $mineActive = static function (Issue $issue) use ($currentUser, $states): bool {
            return $issue->assignee === $currentUser && Format::statePriority($issue->state, $states) !== 99;
        };
        usort(
            $issues,
            static function (Issue $a, Issue $b) use ($mineActive, $states): int {
                $aMine = $mineActive($a);
                $bMine = $mineActive($b);
                if ($aMine !== $bMine) {
                    return $aMine ? -1 : 1;
                }

                return Format::statePriority($a->state, $states) <=> Format::statePriority($b->state, $states);
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

        $format = "%-8s %-8s %-8s %-12s %-8s %-14s %-11s %-11s %-7s %-60s %s\n";
        $printRule = static function () use ($format): void {
            printf(
                $format,
                str_repeat('-', 8),
                str_repeat('-', 8),
                str_repeat('-', 8),
                str_repeat('-', 12),
                str_repeat('-', 8),
                str_repeat('-', 14),
                str_repeat('-', 11),
                str_repeat('-', 11),
                str_repeat('-', 7),
                str_repeat('-', 60),
                str_repeat('-', 44),
            );
        };
        printf($format, 'ID', 'TYPE', 'CAT.', 'STATE', 'ROLES', 'ASSIGNEE', '   SPENT', '    ALL', '  EST  ', 'TITLE', 'LINK');
        $printRule();
        $lastWasMine = false;
        $lastWasFinished = false;
        $afterMinePrinted = false;
        $beforeFinishedPrinted = false;
        $first = true;
        foreach ($issues as $issue) {
            $isMine = $mineActive($issue);
            $isFinished = Format::statePriority($issue->state, $states) === 99;
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
            $custText = $issue->customers !== []
                ? '(' . implode(', ', $issue->customers) . ')'
                : '';
            $custWidth = $custText === '' ? 0 : 1 + mb_strwidth($custText);
            $titleBudget = max(0, 60 - $custWidth);
            $titleTrimmed = mb_strwidth($issue->title) > $titleBudget
                ? mb_strimwidth($issue->title, 0, $titleBudget, '…')
                : $issue->title;
            $title = Ansi::link($url, $titleTrimmed);
            $visibleLen = mb_strwidth($titleTrimmed);
            if ($custText !== '') {
                $title .= ' ' . Ansi::lblue($custText);
                $visibleLen += $custWidth;
            }
            $title .= str_repeat(' ', max(0, 60 - $visibleLen));
            printf(
                $format,
                Ansi::link($url, sprintf('%-8s', $issue->id)),
                $issue->type,
                Format::category($issue->category, $this->categoryColors),
                Format::state($issue->state, $states),
                Format::roles($issue->roles),
                Format::assignee($issue->assignee, $this->currentUser),
                $mine === 0 ? self::spentDash() : Format::spent($mine, Ansi::lgreen(...)),
                $issue->spent === 0 ? self::spentDash() : Format::spent($issue->spent),
                Format::estimation($issue->estimation),
                $title,
                Ansi::lblack(Ansi::link($url, $url)),
            );
        }
    }

    /**
     * Renders a dim dash sized to the same 11-char-wide cell `Format::spent`
     * produces in aligned mode. Applied per-column to SPENT (mine) and ALL
     * (total) whenever that column's minutes are zero — keeps the visual
     * focus on actually-tracked time and reads "no time logged" at a glance.
     */
    private static function spentDash(): string
    {
        return '        ' . Ansi::lblack('-') . '  ';
    }
}

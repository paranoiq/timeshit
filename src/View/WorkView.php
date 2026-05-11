<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Util\Ansi;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\WorkItem;
use function array_keys;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function rtrim;
use function sprintf;
use function str_repeat;
use function usort;

final class WorkView
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @param list<WorkItem> $workItems
     * @param list<Issue> $issues
     */
    public function render(array $workItems, array $issues): void
    {
        if ($workItems === []) {
            echo "No work items.\n";
            return;
        }

        $titleByIssueId = [];
        foreach ($issues as $issue) {
            $titleByIssueId[$issue->id] = $issue->title;
        }

        $baseUrl = rtrim($this->baseUrl, '/');
        usort($workItems, static fn(WorkItem $a, WorkItem $b): int => $a->date <=> $b->date);

        /** @var array<string, list<int>> $itemsByDate */
        $itemsByDate = [];
        /** @var array<string, int> $weekTotal */
        $weekTotal = [];
        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($workItems as $idx => $item) {
            $weekKey = (new DateTimeImmutable($item->date))->format('o-\WW');
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $item->minutes;
            $dayTotal[$item->date] = ($dayTotal[$item->date] ?? 0) + $item->minutes;
            $itemsByDate[$item->date][] = $idx;
        }

        $dates = Workdays::expand(array_keys($itemsByDate));

        $currentWeek = '';
        foreach ($dates as $date) {
            $dt = new DateTimeImmutable($date);
            $weekKey = $dt->format('o-\WW');

            if ($weekKey !== $currentWeek) {
                $weekColor = $weekTotal[$weekKey] >= 40 * 60 ? Ansi::lgreen(...) : Ansi::red(...);
                echo "\n" . $weekColor(sprintf('%-16s', $weekKey)) . '  ' . Format::spent($weekTotal[$weekKey], $weekColor) . "\n";
                $currentWeek = $weekKey;
            }

            $dayMinutes = $dayTotal[$date] ?? 0;
            $isWeekend = (int) $dt->format('N') >= 6;
            $dayColor = match (true) {
                $isWeekend => Ansi::yellow(...),
                $dayMinutes >= 8 * 60 => Ansi::lgreen(...),
                default => Ansi::red(...),
            };
            $dayLabel = $dt->format('l j.n.');
            echo '  ' . $dayColor(sprintf('%-14s', $dayLabel)) . '  ' . Format::spent($dayMinutes, $dayColor) . "\n";

            foreach ($itemsByDate[$date] ?? [] as $idx) {
                $item = $workItems[$idx];
                $url = $baseUrl . '/issue/' . $item->issueId;
                $type = Format::type($item->type);
                $title = self::pad(mb_strimwidth($titleByIssueId[$item->issueId] ?? '', 0, 50, '…'), 50);
                $text = $item->text === '' ? '' : '  ' . Ansi::lblack($item->text);
                echo sprintf(
                    "    %s  %s  %s  %s%s\n",
                    Ansi::link($url, sprintf('%-12s', $item->issueId)),
                    Format::spent($item->minutes),
                    $type,
                    $title,
                    $text,
                );
            }
        }
    }

    private static function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}

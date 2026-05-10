<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use function date;
use function intdiv;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function rtrim;
use function sprintf;
use function str_repeat;
use function time;
use function usort;

final class RecordsView
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @param list<Record> $items
     * @param array<string, string> $titleByIssueId
     */
    public function render(array $items, array $titleByIssueId): void
    {
        if ($items === []) {
            echo "No tracked entries.\n";
            return;
        }

        $baseUrl = rtrim($this->baseUrl, '/');
        $now = time();

        usort($items, static function (Record $a, Record $b): int {
            return (new DateTimeImmutable($b->startedAt))->getTimestamp()
                <=> (new DateTimeImmutable($a->startedAt))->getTimestamp();
        });

        /** @var array<string, int> $weekTotal */
        $weekTotal = [];
        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($items as $item) {
            $start = (new DateTimeImmutable($item->startedAt))->getTimestamp();
            $end = $item->endedAt !== null
                ? (new DateTimeImmutable($item->endedAt))->getTimestamp()
                : $now;
            $minutes = max(0, intdiv($end - $start, 60));
            $weekKey = date('o-\WW', $start);
            $dayKey = date('Y-m-d', $start);
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $minutes;
            $dayTotal[$dayKey] = ($dayTotal[$dayKey] ?? 0) + $minutes;
        }

        $currentWeek = '';
        $currentDay = '';
        foreach ($items as $item) {
            $start = (new DateTimeImmutable($item->startedAt))->getTimestamp();
            $end = $item->endedAt !== null
                ? (new DateTimeImmutable($item->endedAt))->getTimestamp()
                : $now;
            $minutes = max(0, intdiv($end - $start, 60));
            $weekKey = date('o-\WW', $start);
            $dayKey = date('Y-m-d', $start);

            if ($weekKey !== $currentWeek) {
                $weekColor = $weekTotal[$weekKey] >= 40 * 60 ? Ansi::lgreen(...) : Ansi::red(...);
                echo "\n" . $weekColor(sprintf('%-16s', $weekKey)) . '  ' . Format::spent($weekTotal[$weekKey], $weekColor) . "\n";
                $currentWeek = $weekKey;
                $currentDay = '';
            }
            if ($dayKey !== $currentDay) {
                $dayLabel = date('l j.n.', $start);
                $isWeekend = (int) date('N', $start) >= 6;
                $dayColor = match (true) {
                    $isWeekend => Ansi::yellow(...),
                    $dayTotal[$dayKey] >= 8 * 60 => Ansi::lgreen(...),
                    default => Ansi::red(...),
                };
                echo '  ' . $dayColor(sprintf('%-14s', $dayLabel)) . '  ' . Format::spent($dayTotal[$dayKey], $dayColor) . "\n";
                $currentDay = $dayKey;
            }

            $url = $baseUrl . '/issue/' . $item->issueId;
            $type = Format::type($item->type);
            $title = self::pad(mb_strimwidth($titleByIssueId[$item->issueId] ?? '', 0, 30, '…'), 30);
            $startStr = date('H:i', $start);
            $endStr = $item->endedAt !== null ? date('H:i', $end) : Ansi::lgreen('  …  ');
            $time = $startStr . Ansi::lblack('–') . $endStr;
            $commentText = $item->comment === '' ? '' : ' ' . Ansi::lblack($item->comment);
            $comment = '  ' . Format::recordId($item->id) . $commentText;
            echo sprintf(
                "    %s  %s  %s  %s  %s%s\n",
                Ansi::link($url, sprintf('%-12s', $item->issueId)),
                Format::spent($minutes),
                $type,
                $title,
                $time,
                $comment,
            );
        }
    }

    private static function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}

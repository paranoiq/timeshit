<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use function array_keys;
use function date;
use function intdiv;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function rtrim;
use function sprintf;
use function str_repeat;
use function substr;
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
            return (new DateTimeImmutable($a->startedAt))->getTimestamp()
                <=> (new DateTimeImmutable($b->startedAt))->getTimestamp();
        });

        /** @var array<string, list<int>> $itemsByDate */
        $itemsByDate = [];
        /** @var array<string, int> $weekTotal */
        $weekTotal = [];
        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($items as $idx => $item) {
            $start = (new DateTimeImmutable($item->startedAt))->getTimestamp();
            $end = $item->endedAt !== null
                ? (new DateTimeImmutable($item->endedAt))->getTimestamp()
                : $now;
            $minutes = max(0, intdiv($end - $start, 60));
            $weekKey = date('o-\WW', $start);
            $dayKey = substr($item->startedAt, 0, 10);
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $minutes;
            $dayTotal[$dayKey] = ($dayTotal[$dayKey] ?? 0) + $minutes;
            $itemsByDate[$dayKey][] = $idx;
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
                $item = $items[$idx];
                $start = (new DateTimeImmutable($item->startedAt))->getTimestamp();
                $end = $item->endedAt !== null
                    ? (new DateTimeImmutable($item->endedAt))->getTimestamp()
                    : $now;
                $minutes = max(0, intdiv($end - $start, 60));
                $url = $baseUrl . '/issue/' . $item->issueId;
                $type = Format::type($item->type);
                $title = self::pad(mb_strimwidth($titleByIssueId[$item->issueId] ?? '', 0, 30, '…'), 30);
                $startStr = date('H:i', $start);
                $endStr = $item->endedAt !== null ? date('H:i', $end) : Ansi::lgreen('  …  ');
                $time = $startStr . Ansi::lblack('–') . $endStr;
                $noteText = $item->note === '' ? '' : ' ' . Ansi::lblack($item->note);
                $note = '  ' . Format::recordId($item->id) . $noteText;
                echo sprintf(
                    "    %s  %s  %s  %s  %s%s  %s\n",
                    Ansi::link($url, sprintf('%-12s', $item->issueId)),
                    Format::spent($minutes),
                    $type,
                    $title,
                    $time,
                    $note,
                    Format::status($item->status),
                );
            }
        }
    }

    private static function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}

<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use function array_keys;
use function date;
use function implode;
use function in_array;
use function intdiv;
use function ksort;
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
    public function render(array $items, array $titleByIssueId, bool $details = false): void
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

        if (!$details) {
            $this->renderGrouped($items, $titleByIssueId, $baseUrl, $now);
            return;
        }

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
            $countedMinutes = $item->status === 'untracked' ? 0 : $minutes;
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $countedMinutes;
            $dayTotal[$dayKey] = ($dayTotal[$dayKey] ?? 0) + $countedMinutes;
            $itemsByDate[$dayKey][] = $idx;
        }

        $dates = Workdays::expand(array_keys($itemsByDate));

        $currentWeek = '';
        foreach ($dates as $date) {
            $dt = new DateTimeImmutable($date);
            $weekKey = $dt->format('o-\WW');

            if ($weekKey !== $currentWeek) {
                $weekColor = $weekTotal[$weekKey] >= 40 * 60 ? Ansi::lgreen(...) : Ansi::red(...);
                echo "\n" . $weekColor(sprintf('%-18s', $weekKey)) . '  ' . Format::spent($weekTotal[$weekKey], $weekColor) . "\n";
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
            echo '  ' . $dayColor(sprintf('%-16s', $dayLabel)) . '  ' . Format::spent($dayMinutes, $dayColor) . "\n";

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
                $idText = sprintf('%-12s', $item->issueId);
                if ($item->status === 'synced') {
                    $idText = Ansi::lblack($idText);
                    $title = Ansi::lblack($title);
                }
                $startStr = date('H:i', $start);
                $endStr = $item->endedAt !== null ? date('H:i', $end) : Ansi::lgreen('  …  ');
                $time = $startStr . Ansi::lblack('–') . $endStr;
                $noteText = $item->note === '' ? '' : ' ' . Ansi::lblack('"' . $item->note . '"');
                $note = '  ' . Format::recordId($item->id) . $noteText;
                $spent = $item->status === 'untracked'
                    ? Format::spent($minutes, Ansi::lblack(...))
                    : Format::spent($minutes);
                echo sprintf(
                    "    %s %s  %s  %s  %s  %s%s  %s\n",
                    self::recordIndicator($item),
                    Ansi::link($url, $idText),
                    $spent,
                    $type,
                    $title,
                    $time,
                    $note,
                    Format::status($item->status),
                );
            }
        }
    }

    /**
     * @param list<Record> $items
     * @param array<string, string> $titleByIssueId
     */
    private function renderGrouped(array $items, array $titleByIssueId, string $baseUrl, int $now): void
    {
        /** @var array<string, array{date: string, issueId: string, type: string, minutes: int, notes: list<string>, count: int, allSynced: bool, hasOpen: bool, hasFailed: bool}> $groups */
        $groups = [];
        foreach ($items as $item) {
            if ($item->status === 'untracked') {
                continue;
            }
            $date = substr($item->startedAt, 0, 10);
            $start = (new DateTimeImmutable($item->startedAt))->getTimestamp();
            $end = $item->endedAt !== null
                ? (new DateTimeImmutable($item->endedAt))->getTimestamp()
                : $now;
            $minutes = max(0, intdiv($end - $start, 60));
            $key = "{$date}|{$item->issueId}|{$item->type}";
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $date,
                    'issueId' => $item->issueId,
                    'type' => $item->type,
                    'minutes' => 0,
                    'notes' => [],
                    'count' => 0,
                    'allSynced' => true,
                    'hasOpen' => false,
                    'hasFailed' => false,
                ];
            }
            $groups[$key]['minutes'] += $minutes;
            $groups[$key]['count']++;
            if ($item->note !== '' && !in_array($item->note, $groups[$key]['notes'], true)) {
                $groups[$key]['notes'][] = $item->note;
            }
            if ($item->status !== 'synced') {
                $groups[$key]['allSynced'] = false;
            }
            if ($item->endedAt === null) {
                $groups[$key]['hasOpen'] = true;
            }
            if ($item->status === 'failed') {
                $groups[$key]['hasFailed'] = true;
            }
        }
        ksort($groups);

        /** @var array<string, list<string>> $keysByDate */
        $keysByDate = [];
        /** @var array<string, int> $weekTotal */
        $weekTotal = [];
        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($groups as $key => $g) {
            $weekKey = (new DateTimeImmutable($g['date']))->format('o-\WW');
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $g['minutes'];
            $dayTotal[$g['date']] = ($dayTotal[$g['date']] ?? 0) + $g['minutes'];
            $keysByDate[$g['date']][] = $key;
        }
        foreach ($keysByDate as $d => $keys) {
            usort($keys, static fn(string $a, string $b): int => $groups[$b]['minutes'] <=> $groups[$a]['minutes']);
            $keysByDate[$d] = $keys;
        }

        $dates = Workdays::expand(array_keys($keysByDate));

        $currentWeek = '';
        foreach ($dates as $date) {
            $dt = new DateTimeImmutable($date);
            $weekKey = $dt->format('o-\WW');

            if ($weekKey !== $currentWeek) {
                $weekMinutes = $weekTotal[$weekKey] ?? 0;
                $weekColor = $weekMinutes >= 40 * 60 ? Ansi::lgreen(...) : Ansi::red(...);
                echo "\n" . $weekColor(sprintf('%-18s', $weekKey)) . '  ' . Format::spent($weekMinutes, $weekColor) . "\n";
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
            echo '  ' . $dayColor(sprintf('%-16s', $dayLabel)) . '  ' . Format::spent($dayMinutes, $dayColor) . "\n";

            foreach ($keysByDate[$date] ?? [] as $key) {
                $g = $groups[$key];
                $url = $baseUrl . '/issue/' . $g['issueId'];
                $type = Format::type($g['type']);
                $title = self::pad(mb_strimwidth($titleByIssueId[$g['issueId']] ?? '', 0, 30, '…'), 30);
                $idText = sprintf('%-12s', $g['issueId']);
                if ($g['allSynced']) {
                    $idText = Ansi::lblack($idText);
                    $title = Ansi::lblack($title);
                }
                $count = $g['count'] > 1 ? Ansi::lblack(sprintf('×%-2d', $g['count'])) : '   ';
                $notes = implode(' | ', $g['notes']);
                $notesPart = $notes === '' ? '' : '  ' . Ansi::lblack('"' . $notes . '"');
                echo sprintf(
                    "    %s %s  %s  %s  %s  %s%s\n",
                    self::groupIndicator($g),
                    Ansi::link($url, $idText),
                    Format::spent($g['minutes']),
                    $type,
                    $title,
                    $count,
                    $notesPart,
                );
            }
        }
    }

    private static function recordIndicator(Record $r): string
    {
        if ($r->endedAt === null) {
            return Format::indicator('open');
        }

        return Format::indicator(match ($r->status) {
            'synced' => 'archived',
            'failed' => 'failed',
            default  => 'local',
        });
    }

    /** @param array{allSynced: bool, hasOpen: bool, hasFailed: bool} $g */
    private static function groupIndicator(array $g): string
    {
        return Format::indicator(match (true) {
            $g['hasFailed'] => 'failed',
            $g['hasOpen']   => 'open',
            $g['allSynced'] => 'archived',
            default         => 'local',
        });
    }

    private static function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}

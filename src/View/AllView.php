<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\WorkItem;
use function array_keys;
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

/**
 * Renders a unified view of YouTrack work items (already synced) and locally
 * tracked records (not yet synced). Each row carries a status marker so the
 * two are visually distinct: ● green = synced, ▶ green = open (still running),
 * ○ yellow = local-only closed, ✗ red = failed-to-sync (reserved for future
 * use).
 */
final class AllView
{
    /**
     * @param array<string, string> $typeColors canonical type name => Ansi color name
     * @param array<string, string> $typeShortNames canonical type name => display short name
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $typeColors,
        private readonly array $typeShortNames,
    ) {}

    /**
     * @param list<WorkItem> $workItems
     * @param list<Record>   $records
     * @param list<Issue>    $issues
     */
    public function render(array $workItems, array $records, array $issues, bool $details = false): void
    {
        if ($workItems === [] && $records === []) {
            echo "Nothing tracked.\n";

            return;
        }

        $titleByIssueId = [];
        foreach ($issues as $issue) {
            $titleByIssueId[$issue->id] = $issue->title;
        }

        $now = time();
        /** @var list<array{status: string, recordStatus: ?string, date: string, minutes: int, issueId: string, recordId: ?int, type: string, text: string}> $rows */
        $rows = [];
        foreach ($workItems as $wi) {
            $rows[] = [
                'status' => 'synced',
                'recordStatus' => null,
                'date' => $wi->date,
                'minutes' => $wi->minutes,
                'issueId' => $wi->issueId,
                'recordId' => null,
                'type' => $wi->type,
                'text' => $wi->text,
            ];
        }
        foreach ($records as $r) {
            $start = (new DateTimeImmutable($r->startedAt))->getTimestamp();
            $end = $r->endedAt !== null
                ? (new DateTimeImmutable($r->endedAt))->getTimestamp()
                : $now;
            $minutes = max(0, intdiv($end - $start, 60));
            $rows[] = [
                'status' => $r->endedAt === null ? 'open' : 'local',
                'recordStatus' => $r->status,
                'date' => substr($r->startedAt, 0, 10),
                'minutes' => $minutes,
                'issueId' => $r->issueId,
                'recordId' => $r->id,
                'type' => $r->type,
                'text' => $r->note,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);

        $baseUrl = rtrim($this->baseUrl, '/');

        if (!$details) {
            $this->renderGrouped($rows, $titleByIssueId, $baseUrl);
            return;
        }

        /** @var array<string, list<int>> $rowsByDate */
        $rowsByDate = [];
        /** @var array<string, int> $weekTotal */
        $weekTotal = [];
        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($rows as $idx => $row) {
            $weekKey = (new DateTimeImmutable($row['date']))->format('o-\WW');
            $countedMinutes = $row['recordStatus'] === 'untracked' ? 0 : $row['minutes'];
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $countedMinutes;
            $dayTotal[$row['date']] = ($dayTotal[$row['date']] ?? 0) + $countedMinutes;
            $rowsByDate[$row['date']][] = $idx;
        }

        $dates = Workdays::expand(array_keys($rowsByDate));

        echo '  ' . Ansi::lblack('Legend: ')
            . Format::indicator('synced') . ' ' . Ansi::lblack('synced  ')
            . Format::indicator('open')   . ' ' . Ansi::lblack('open  ')
            . Format::indicator('local')  . ' ' . Ansi::lblack('local  ')
            . Format::indicator('failed') . ' ' . Ansi::lblack('failed') . "\n";

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

            foreach ($rowsByDate[$date] ?? [] as $idx) {
                $row = $rows[$idx];
                $url = $baseUrl . '/issue/' . $row['issueId'];
                $type = Format::type($row['type'], $this->typeColors, $this->typeShortNames);
                $title = self::pad(mb_strimwidth($titleByIssueId[$row['issueId']] ?? '', 0, 50, '…'), 50);
                $textTail = $row['text'] === '' ? '' : ' ' . Ansi::lblack($row['text']);
                $text = $row['recordId'] !== null
                    ? '  ' . Format::recordId($row['recordId']) . $textTail
                    : ($textTail === '' ? '' : ' ' . $textTail);
                $recordStatus = $row['recordStatus'] !== null ? '  ' . Format::status($row['recordStatus']) : '';
                $spent = $row['recordStatus'] === 'untracked'
                    ? Format::spent($row['minutes'], Ansi::lblack(...))
                    : Format::spent($row['minutes']);
                echo sprintf(
                    "    %s %s  %s  %s  %s%s%s\n",
                    Format::indicator($row['status']),
                    Ansi::link($url, sprintf('%-12s', $row['issueId'])),
                    $spent,
                    $type,
                    $title,
                    $text,
                    $recordStatus,
                );
            }
        }
    }

    /**
     * @param list<array{status: string, recordStatus: ?string, date: string, minutes: int, issueId: string, recordId: ?int, type: string, text: string}> $rows
     * @param array<string, string> $titleByIssueId
     */
    private function renderGrouped(array $rows, array $titleByIssueId, string $baseUrl): void
    {
        /** @var array<string, array{date: string, issueId: string, type: string, minutes: int, notes: list<string>, count: int, status: string, hasSynced: bool, hasFailed: bool, hasOpen: bool, hasLocal: bool}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            if ($row['recordStatus'] === 'untracked') {
                continue;
            }
            $key = $row['date'] . '|' . $row['issueId'] . '|' . $row['type'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $row['date'],
                    'issueId' => $row['issueId'],
                    'type' => $row['type'],
                    'minutes' => 0,
                    'notes' => [],
                    'count' => 0,
                    'status' => 'local',
                    'hasSynced' => false,
                    'hasFailed' => false,
                    'hasOpen' => false,
                    'hasLocal' => false,
                ];
            }
            $groups[$key]['minutes'] += $row['minutes'];
            $groups[$key]['count']++;
            if ($row['text'] !== '' && !in_array($row['text'], $groups[$key]['notes'], true)) {
                $groups[$key]['notes'][] = $row['text'];
            }
            if ($row['status'] === 'synced') {
                $groups[$key]['hasSynced'] = true;
            } elseif ($row['status'] === 'open') {
                $groups[$key]['hasOpen'] = true;
            } else {
                $groups[$key]['hasLocal'] = true;
            }
            if ($row['recordStatus'] === 'failed') {
                $groups[$key]['hasFailed'] = true;
            }
        }
        foreach ($groups as $k => $g) {
            $groups[$k]['status'] = match (true) {
                $g['hasFailed'] => 'failed',
                $g['hasOpen']   => 'open',
                $g['hasSynced'] && !$g['hasLocal'] => 'synced',
                default => 'local',
            };
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

        echo '  ' . Ansi::lblack('Legend: ')
            . Format::indicator('synced') . ' ' . Ansi::lblack('synced  ')
            . Format::indicator('open')   . ' ' . Ansi::lblack('open  ')
            . Format::indicator('local')  . ' ' . Ansi::lblack('local  ')
            . Format::indicator('failed') . ' ' . Ansi::lblack('failed') . "\n";

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
                $type = Format::type($g['type'], $this->typeColors, $this->typeShortNames);
                $title = self::pad(mb_strimwidth($titleByIssueId[$g['issueId']] ?? '', 0, 50, '…'), 50);
                $count = $g['count'] > 1 ? '  ' . Ansi::lblack(sprintf('×%-2d', $g['count'])) : '     ';
                $notes = implode(' | ', $g['notes']);
                $notesPart = $notes === '' ? '' : ' ' . Ansi::lblack('"' . $notes . '"');
                echo sprintf(
                    "    %s %s  %s  %s  %s%s%s\n",
                    Format::indicator($g['status']),
                    Ansi::link($url, sprintf('%-12s', $g['issueId'])),
                    Format::spent($g['minutes']),
                    $type,
                    $title,
                    $count,
                    $notesPart,
                );
            }
        }
    }

    private static function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}
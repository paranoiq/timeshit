<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\WorkItem;
use function array_keys;
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

/**
 * Renders a unified view of YouTrack work items (already synced) and locally
 * tracked records (not yet synced). Each row carries a status marker so the
 * two are visually distinct: ● green = synced, ▶ green = open (still running),
 * ○ yellow = local-only closed, ✗ red = failed-to-sync (reserved for future
 * use).
 */
final class AllView
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @param list<WorkItem> $workItems
     * @param list<Record>   $records
     * @param list<Issue>    $issues
     */
    public function render(array $workItems, array $records, array $issues): void
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

        /** @var array<string, list<int>> $rowsByDate */
        $rowsByDate = [];
        /** @var array<string, int> $weekTotal */
        $weekTotal = [];
        /** @var array<string, int> $dayTotal */
        $dayTotal = [];
        foreach ($rows as $idx => $row) {
            $weekKey = (new DateTimeImmutable($row['date']))->format('o-\WW');
            $weekTotal[$weekKey] = ($weekTotal[$weekKey] ?? 0) + $row['minutes'];
            $dayTotal[$row['date']] = ($dayTotal[$row['date']] ?? 0) + $row['minutes'];
            $rowsByDate[$row['date']][] = $idx;
        }

        $dates = Workdays::expand(array_keys($rowsByDate));

        echo '  ' . Ansi::lblack('Legend: ')
            . self::statusIndicator('synced') . ' ' . Ansi::lblack('synced  ')
            . self::statusIndicator('open')   . ' ' . Ansi::lblack('open  ')
            . self::statusIndicator('local')  . ' ' . Ansi::lblack('local  ')
            . self::statusIndicator('failed') . ' ' . Ansi::lblack('failed') . "\n";

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
                $type = Format::type($row['type']);
                $title = self::pad(mb_strimwidth($titleByIssueId[$row['issueId']] ?? '', 0, 50, '…'), 50);
                $textTail = $row['text'] === '' ? '' : ' ' . Ansi::lblack($row['text']);
                $text = $row['recordId'] !== null
                    ? '  ' . Format::recordId($row['recordId']) . $textTail
                    : ($textTail === '' ? '' : ' ' . $textTail);
                $recordStatus = $row['recordStatus'] !== null ? '  ' . Format::status($row['recordStatus']) : '';
                echo sprintf(
                    "    %s %s  %s  %s  %s%s%s\n",
                    self::statusIndicator($row['status']),
                    Ansi::link($url, sprintf('%-12s', $row['issueId'])),
                    Format::spent($row['minutes']),
                    $type,
                    $title,
                    $text,
                    $recordStatus,
                );
            }
        }
    }

    private static function statusIndicator(string $status): string
    {
        return match ($status) {
            'synced' => Ansi::lgreen('●'),
            'open'   => Ansi::lgreen('▶'),
            'local'  => Ansi::lyellow('○'),
            'failed' => Ansi::red('✗'),
            default  => ' ',
        };
    }

    private static function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}
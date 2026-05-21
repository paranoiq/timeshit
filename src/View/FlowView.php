<?php declare(strict_types=1);

namespace Timeshit\View;

use Closure;
use DateTimeImmutable;
use Timeshit\Format;
use Timeshit\Local\Record;
use Timeshit\Util\Ansi;
use function count;
use function intdiv;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_repeat;
use function substr;
use function usort;

/**
 * Renders a weekly horizontal timeline. Per day, four lines are printed:
 *   1. Hour ruler  (dim hh:mm labels, ':' centered over the tick column)
 *   2. Timeline    (+---+ bar, colored by type)
 *   3. Type line   (first letter of type, colored; followed by issue title)
 *   4. Issue line  (numeric issue id, stripped of LETTERS- prefix; duration)
 *
 * Each character = 5 minutes; timeline starts at 07:00.
 * Adjacent records share a single '+' boundary; all four lines stay column-aligned.
 */
final class FlowView
{
    private const MINS_PER_CHAR = 4;
    private const START_HOUR = 7;

    /**
     * @param array<string, string> $typeColors    canonical type name => Ansi color name
     * @param array<string, string> $typeShortNames canonical type name => display short name
     */
    public function __construct(
        private readonly array $typeColors,
        private readonly array $typeShortNames,
    ) {}

    /**
     * @param list<Record>          $records
     * @param array<string, string> $titleByIssueId
     */
    public function render(
        array $records,
        array $titleByIssueId,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $now,
    ): void {
        $nowStr = $now->format('Y-m-d H:i');

        /** @var array<string, list<Record>> $byDate */
        $byDate = [];
        foreach ($records as $r) {
            if ($r->status === 'day') {
                continue;
            }
            $date = substr($r->startedAt, 0, 10);
            $byDate[$date][] = $r;
        }

        $today = $now->format('Y-m-d');

        for ($i = 0; $i < 14; $i++) {
            $day  = $rangeStart->modify("+{$i} days");
            $date = $day->format('Y-m-d');

            if ($date > $today) {
                break;
            }

            $isWeekend  = (int) $day->format('N') >= 6;
            $dayRecords = $byDate[$date] ?? [];

            if ($isWeekend && $dayRecords === []) {
                continue;
            }

            usort($dayRecords, static fn(Record $a, Record $b): int => $a->startedAt <=> $b->startedAt);

            $dayMinutes = 0;
            foreach ($dayRecords as $r) {
                if ($r->status === 'untracked' || $r->status === 'day') {
                    continue;
                }
                $s = new DateTimeImmutable($r->startedAt);
                $e = $r->endedAt !== null ? new DateTimeImmutable($r->endedAt) : new DateTimeImmutable($nowStr);
                $dayMinutes += max(0, intdiv($e->getTimestamp() - $s->getTimestamp(), 60));
            }
            $totalStr = $dayMinutes > 0 ? '  ' . self::dimUnits(Format::minutes($dayMinutes)) : '';

            echo "\n" . Ansi::lwhite($day->format('D j.n.')) . $totalStr . "\n";

            if ($dayRecords === []) {
                echo Ansi::lblack('(no records)') . "\n";
                continue;
            }

            $this->renderDay($dayRecords, $titleByIssueId, $nowStr);
        }
    }

    /**
     * @param list<Record>          $records sorted by startedAt
     * @param array<string, string> $titleByIssueId
     */
    private function renderDay(array $records, array $titleByIssueId, string $nowStr): void
    {
        $startOfDay = self::START_HOUR * 60;

        /** @var list<array{startPos: int, endPos: int, record: Record, minutes: int}> $slots */
        $slots = [];
        $maxEndPos = 0;

        foreach ($records as $r) {
            if ($r->status === 'untracked') {
                continue;
            }
            $startDt = new DateTimeImmutable($r->startedAt);
            $endDt = $r->endedAt !== null
                ? new DateTimeImmutable($r->endedAt)
                : new DateTimeImmutable($nowStr);

            $startMin = (int) $startDt->format('G') * 60 + (int) $startDt->format('i');
            $endMin   = (int) $endDt->format('G') * 60 + (int) $endDt->format('i');

            $startPos = max(0, intdiv($startMin - $startOfDay, self::MINS_PER_CHAR));
            $endPos   = max($startPos + 1, intdiv($endMin - $startOfDay, self::MINS_PER_CHAR));
            $minutes  = max(0, intdiv($endDt->getTimestamp() - $startDt->getTimestamp(), 60));
            $maxEndPos = max($maxEndPos, $endPos);

            $slots[] = [
                'startPos' => $startPos,
                'endPos'   => $endPos,
                'record'   => $r,
                'minutes'  => $minutes,
            ];
        }

        $midLine   = '';
        $typeLine  = '';
        $issueLine = '';
        $pos = 0;
        $n = count($slots);

        for ($i = 0; $i < $n; $i++) {
            ['startPos' => $sp, 'endPos' => $ep, 'record' => $r, 'minutes' => $minutes] = $slots[$i];
            $nextSp = $i + 1 < $n ? $slots[$i + 1]['startPos'] : -1;

            // Adjacent records share a single '+'; the closing '+' of the current
            // record is omitted and the next record's opening '+' fills that column.
            // This keeps all four output lines at the same visual width.
            $adjacentToNext = ($nextSp === $ep);

            $gap = $sp - $pos;
            if ($gap > 0) {
                $midLine   .= str_repeat(' ', $gap);
                $typeLine  .= str_repeat(' ', $gap);
                $issueLine .= str_repeat(' ', $gap);
                $pos = $sp;
            }

            $color    = $this->recordColor($r);
            $interior = max(0, $ep - $sp - 1);
            $trailing = $adjacentToNext ? '' : ' ';

            // textWidth = columns for label text (ep-sp); non-adjacent adds 1 trailing space
            $textWidth  = $ep - $sp;
            $typeLetter = mb_substr($this->typeShortNames[$r->type] ?? $r->type, 0, 1);

            // Mid: colored timeline bar; type letter at start, dash at loose end
            $midSeg  = $typeLetter . str_repeat('-', $interior) . ($adjacentToNext ? '' : '-');
            $midLine .= self::paint($color, $midSeg);

            $title     = $titleByIssueId[$r->issueId] ?? '';
            $titleText = self::fit($title, $textWidth);
            $typeLine .= $titleText
                . str_repeat(' ', $textWidth - Ansi::length($titleText))
                . $trailing;

            $issueNum  = self::stripPrefix($r->issueId);
            $issueText = $issueNum;
            if ($textWidth > mb_strlen($issueText) + 2) {
                $issueText .= ' ' . Format::minutes($minutes);
            }
            $issueText  = self::fit($issueText, $textWidth);
            $issueLine .= self::dimUnits($issueText)
                . str_repeat(' ', $textWidth - Ansi::length($issueText))
                . $trailing;

            $pos = $adjacentToNext ? $ep : $ep + 1;
        }

        echo Ansi::lblack($this->buildRuler($maxEndPos)) . "\n";
        echo "{$midLine}\n";
        echo "{$typeLine}\n";
        echo "{$issueLine}\n";
    }

    /**
     * Builds a plain-text hour ruler of the given timeline width.
     * Each `hh:mm` label is placed so its ':' falls exactly at the timeline
     * column that represents that hour.
     */
    private function buildRuler(int $maxEndPos): string
    {
        // Extra room so the last label never gets cut off on the right.
        $width = $maxEndPos + 5;
        $ruler = str_repeat(' ', $width);
        $charsPerHour = intdiv(60, self::MINS_PER_CHAR); // 12

        for ($h = self::START_HOUR; ; $h++) {
            $col = ($h - self::START_HOUR) * $charsPerHour;
            if ($col > $maxEndPos + $charsPerHour) {
                break;
            }
            // No leading zero; colon index depends on number of hour digits.
            $label    = "{$h}:00";
            $colonIdx = $h < 10 ? 1 : 2;
            // Place ':' at $col; clamp so the first label is never pushed off-screen.
            $labelStart = max(0, $col - $colonIdx);
            for ($c = 0, $len = strlen($label); $c < $len; $c++) {
                $p = $labelStart + $c;
                if ($p < $width) {
                    $ruler[$p] = $label[$c];
                }
            }
        }

        return rtrim($ruler);
    }

    private function recordColor(Record $r): ?Closure
    {
        $name = $this->typeColors[$r->type] ?? '';

        return Ansi::byName($name);
    }

    private static function paint(?Closure $color, string $text): string
    {
        return $color !== null ? $color($text) : $text;
    }

    /** Strips the `LETTERS-` project prefix from a standard issue id, e.g. `SW-4002` → `4002`. */
    private static function stripPrefix(string $issueId): string
    {
        if (preg_match('/^[A-Za-z]+-(\d+)$/', $issueId, $m) === 1) {
            return $m[1];
        }

        return $issueId;
    }

    /** Dims the unit letters (h/m/d) that follow digits, leaving numbers in default color. */
    private static function dimUnits(string $text): string
    {
        // Split on ANSI sequences so their embedded digits (e.g. '0' in '\e[0m')
        // are never treated as unit suffixes. Odd-indexed parts are ANSI codes;
        // even-indexed parts are plain text where the regex may safely apply.
        $parts = preg_split('/(\e\[[0-9;]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }
        $result = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $result .= $part;
            } else {
                $result .= preg_replace_callback(
                    '/(\d+)([hmd])/',
                    static fn(array $m): string => $m[1] . Ansi::lblack($m[2]),
                    $part,
                ) ?? $part;
            }
        }

        return $result;
    }

    private static function fit(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $maxWidth) {
            return $text;
        }

        return mb_substr($text, 0, $maxWidth - 1) . Ansi::lblack('…');
    }
}
<?php declare(strict_types=1);

namespace Timeshit\View;

use DateTimeImmutable;
use function array_keys;
use function date;
use function explode;
use function sort;

final class Workdays
{
    /**
     * Fills in missing Mon–Fri days (capped at today) for any ISO week
     * represented in `$itemDates`, so empty workdays show up in per-day
     * rollups. Returns the union sorted ascending.
     *
     * @param list<string> $itemDates  YYYY-MM-DD strings
     * @return list<string>            sorted ascending
     */
    public static function expand(array $itemDates): array
    {
        $today = date('Y-m-d');
        /** @var array<string, true> $dateSet */
        $dateSet = [];
        /** @var array<string, true> $weekSet */
        $weekSet = [];
        foreach ($itemDates as $d) {
            $dateSet[$d] = true;
            $weekSet[(new DateTimeImmutable($d))->format('o-\WW')] = true;
        }
        foreach (array_keys($weekSet) as $weekKey) {
            $parts = explode('-W', $weekKey);
            $monday = (new DateTimeImmutable())->setISODate((int) $parts[0], (int) $parts[1]);
            for ($i = 0; $i < 5; $i++) {
                $d = $monday->modify("+{$i} day")->format('Y-m-d');
                if ($d <= $today) {
                    $dateSet[$d] = true;
                }
            }
        }
        $dates = array_keys($dateSet);
        sort($dates);

        return $dates;
    }
}
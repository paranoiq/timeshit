<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\View\Workdays;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// A single past-week date fills in Mon–Fri of that week (today's week-of-year is
// far enough in the future relative to the dates picked that nothing is capped).
// 2024-05-15 is a Wednesday — ISO week 2024-W20 runs Mon 13th to Sun 19th.
Assert::same(
    ['2024-05-13', '2024-05-14', '2024-05-15', '2024-05-16', '2024-05-17'],
    Workdays::expand(['2024-05-15']),
);

// Weekend dates show up too (Sunday 2024-05-19), but the weekend slot is NOT
// added for weeks where it has no item.
Assert::same(
    ['2024-05-13', '2024-05-14', '2024-05-15', '2024-05-16', '2024-05-17', '2024-05-19'],
    Workdays::expand(['2024-05-15', '2024-05-19']),
);

// Multiple items on the same day collapse to one entry; duplicates aren't emitted.
Assert::same(
    ['2024-05-13', '2024-05-14', '2024-05-15', '2024-05-16', '2024-05-17'],
    Workdays::expand(['2024-05-15', '2024-05-15', '2024-05-16']),
);

// Two separate weeks both get their workdays filled in.
Assert::same(
    [
        '2024-05-13', '2024-05-14', '2024-05-15', '2024-05-16', '2024-05-17',
        '2024-05-20', '2024-05-21', '2024-05-22', '2024-05-23', '2024-05-24',
    ],
    Workdays::expand(['2024-05-15', '2024-05-22']),
);

// Future workdays are capped at today; an item dated today is still listed
// and no date past today leaks in.
$result = Workdays::expand([$today]);
Assert::contains($today, $result);
foreach ($result as $d) {
    Assert::true($d <= $today, "expected {$d} to be on or before {$today}");
}

// A far-future date is itself returned (it's an explicit item date) but Mon–Fri
// of that future week are all capped away.
$future = (new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d');
Assert::same([$future], Workdays::expand([$future]));

// Empty input yields an empty list.
Assert::same([], Workdays::expand([]));
<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\App;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

$today = new DateTimeImmutable('today');
$ymd = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d');
$shift = static fn(int $days): string => $today->modify("{$days} days")->format('Y-m-d');

// --- defaults ----------------------------------------------------------------

Assert::same($shift(0), $ymd(App::resolveDate(null)));
Assert::same($shift(0), $ymd(App::resolveDate('')));

// --- exact keywords ----------------------------------------------------------

Assert::same($shift(0),  $ymd(App::resolveDate('today')));
Assert::same($shift(-1), $ymd(App::resolveDate('yesterday')));
Assert::same($shift(1),  $ymd(App::resolveDate('tomorrow')));
Assert::same($shift(-2), $ymd(App::resolveDate('ereyesterday')));
Assert::same($shift(2),  $ymd(App::resolveDate('overmorrow')));

// --- unique prefix matches ---------------------------------------------------

Assert::same($shift(0),  $ymd(App::resolveDate('tod')));
Assert::same($shift(1),  $ymd(App::resolveDate('tom')));
Assert::same($shift(-1), $ymd(App::resolveDate('y')));
Assert::same($shift(-1), $ymd(App::resolveDate('yes')));
Assert::same($shift(-2), $ymd(App::resolveDate('e')));
Assert::same($shift(-2), $ymd(App::resolveDate('ere')));
Assert::same($shift(2),  $ymd(App::resolveDate('o')));
Assert::same($shift(2),  $ymd(App::resolveDate('over')));

// --- case-insensitive --------------------------------------------------------

Assert::same($shift(-1), $ymd(App::resolveDate('YES')));
Assert::same($shift(2),  $ymd(App::resolveDate('Over')));
Assert::same($shift(0),  $ymd(App::resolveDate('TODAY')));

// --- ambiguous prefixes ------------------------------------------------------

Assert::exception(
    static fn() => App::resolveDate('t'),
    RuntimeException::class,
    "day: ambiguous date 't', could be: today, tomorrow",
);
Assert::exception(
    static fn() => App::resolveDate('to'),
    RuntimeException::class,
    "day: ambiguous date 'to', could be: today, tomorrow",
);

// --- integer = day-of-month --------------------------------------------------

$year = (int) $today->format('Y');
$month = (int) $today->format('m');
$dom = static fn(int $d): string => sprintf('%04d-%02d-%02d', $year, $month, $d);

Assert::same($dom(1),  $ymd(App::resolveDate('1')));
Assert::same($dom(9),  $ymd(App::resolveDate('9')));
Assert::same($dom(9),  $ymd(App::resolveDate('09')));
Assert::same($dom(15), $ymd(App::resolveDate('15')));

// Last day of the current month is always valid; day after is not.
$lastDay = (int) (new DateTimeImmutable('last day of this month'))->format('j');
Assert::same($dom($lastDay), $ymd(App::resolveDate((string) $lastDay)));
Assert::exception(
    static fn() => App::resolveDate((string) ($lastDay + 1)),
    RuntimeException::class,
    "#invalid day-of-month#",
);
Assert::exception(
    static fn() => App::resolveDate('0'),
    RuntimeException::class,
    "#invalid day-of-month#",
);
Assert::exception(
    static fn() => App::resolveDate('999'),
    RuntimeException::class,
    "#invalid day-of-month#",
);

// --- ISO date fallthrough ----------------------------------------------------

Assert::same('2026-05-15', $ymd(App::resolveDate('2026-05-15')));
Assert::same('2024-02-29', $ymd(App::resolveDate('2024-02-29')));

// --- garbage -----------------------------------------------------------------

Assert::exception(
    static fn() => App::resolveDate('garbage'),
    RuntimeException::class,
    "#invalid date#",
);
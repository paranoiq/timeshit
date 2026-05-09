<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Resolver;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

$today = new DateTimeImmutable('today');
$ymd = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d');
$shift = static fn(int $days): string => $today->modify("{$days} days")->format('Y-m-d');

// --- defaults ----------------------------------------------------------------

Assert::same($shift(0), $ymd(Resolver::resolveDate(null)));
Assert::same($shift(0), $ymd(Resolver::resolveDate('')));

// --- exact keywords ----------------------------------------------------------

Assert::same($shift(0),  $ymd(Resolver::resolveDate('today')));
Assert::same($shift(-1), $ymd(Resolver::resolveDate('yesterday')));
Assert::same($shift(1),  $ymd(Resolver::resolveDate('tomorrow')));
Assert::same($shift(-2), $ymd(Resolver::resolveDate('ereyesterday')));
Assert::same($shift(2),  $ymd(Resolver::resolveDate('overmorrow')));

// --- unique prefix matches ---------------------------------------------------

Assert::same($shift(0),  $ymd(Resolver::resolveDate('tod')));
Assert::same($shift(1),  $ymd(Resolver::resolveDate('tom')));
Assert::same($shift(-1), $ymd(Resolver::resolveDate('y')));
Assert::same($shift(-1), $ymd(Resolver::resolveDate('yes')));
Assert::same($shift(-2), $ymd(Resolver::resolveDate('e')));
Assert::same($shift(-2), $ymd(Resolver::resolveDate('ere')));
Assert::same($shift(2),  $ymd(Resolver::resolveDate('o')));
Assert::same($shift(2),  $ymd(Resolver::resolveDate('over')));

// --- case-insensitive --------------------------------------------------------

Assert::same($shift(-1), $ymd(Resolver::resolveDate('YES')));
Assert::same($shift(2),  $ymd(Resolver::resolveDate('Over')));
Assert::same($shift(0),  $ymd(Resolver::resolveDate('TODAY')));

// --- ambiguous prefixes ------------------------------------------------------

Assert::exception(
    static fn() => Resolver::resolveDate('t'),
    RuntimeException::class,
    "day: ambiguous date 't', could be: today, tomorrow",
);
Assert::exception(
    static fn() => Resolver::resolveDate('to'),
    RuntimeException::class,
    "day: ambiguous date 'to', could be: today, tomorrow",
);

// --- integer = day-of-month --------------------------------------------------

$year = (int) $today->format('Y');
$month = (int) $today->format('m');
$dom = static fn(int $d): string => sprintf('%04d-%02d-%02d', $year, $month, $d);

Assert::same($dom(1),  $ymd(Resolver::resolveDate('1')));
Assert::same($dom(9),  $ymd(Resolver::resolveDate('9')));
Assert::same($dom(9),  $ymd(Resolver::resolveDate('09')));
Assert::same($dom(15), $ymd(Resolver::resolveDate('15')));

// Last day of the current month is always valid; day after is not.
$lastDay = (int) (new DateTimeImmutable('last day of this month'))->format('j');
Assert::same($dom($lastDay), $ymd(Resolver::resolveDate((string) $lastDay)));
Assert::exception(
    static fn() => Resolver::resolveDate((string) ($lastDay + 1)),
    RuntimeException::class,
    "#invalid day-of-month#",
);
Assert::exception(
    static fn() => Resolver::resolveDate('0'),
    RuntimeException::class,
    "#invalid day-of-month#",
);
Assert::exception(
    static fn() => Resolver::resolveDate('999'),
    RuntimeException::class,
    "#invalid day-of-month#",
);

// --- ISO date fallthrough ----------------------------------------------------

Assert::same('2026-05-15', $ymd(Resolver::resolveDate('2026-05-15')));
Assert::same('2024-02-29', $ymd(Resolver::resolveDate('2024-02-29')));

// --- garbage -----------------------------------------------------------------

Assert::exception(
    static fn() => Resolver::resolveDate('garbage'),
    RuntimeException::class,
    "#invalid date#",
);

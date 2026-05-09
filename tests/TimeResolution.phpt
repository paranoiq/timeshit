<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Resolver;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

$existing = '2026-05-09 12:34';
$fmt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

// --- bare HH:MM keeps the date of the existing timestamp ---------------------

Assert::same('2026-05-09 09:30:00', $fmt(Resolver::resolveTime('at', '09:30', $existing)));
Assert::same('2026-05-09 00:00:00', $fmt(Resolver::resolveTime('at', '00:00', $existing)));
Assert::same('2026-05-09 23:59:00', $fmt(Resolver::resolveTime('at', '23:59', $existing)));

// 1-digit hour is accepted (\d{1,2}); minutes still require 2 digits.
Assert::same('2026-05-09 09:30:00', $fmt(Resolver::resolveTime('at', '9:30', $existing)));
Assert::same('2026-05-09 00:05:00', $fmt(Resolver::resolveTime('at', '0:05', $existing)));

// HH:MM also wipes any sub-minute precision the existing timestamp may have.
Assert::same(
    '2026-05-09 11:00:00',
    $fmt(Resolver::resolveTime('at', '11:00', '2026-05-09 12:34:56')),
);

// --- HH:MM out of range ------------------------------------------------------

Assert::exception(
    static fn() => Resolver::resolveTime('at', '24:00', $existing),
    RuntimeException::class,
    "at: invalid time '24:00'",
);
Assert::exception(
    static fn() => Resolver::resolveTime('at', '12:60', $existing),
    RuntimeException::class,
    "at: invalid time '12:60'",
);
Assert::exception(
    static fn() => Resolver::resolveTime('at', '99:99', $existing),
    RuntimeException::class,
    "at: invalid time '99:99'",
);

// --- full date+time fallthrough (DateTimeImmutable parses it) ----------------

Assert::same(
    '2026-05-15 14:30:00',
    $fmt(Resolver::resolveTime('at', '2026-05-15 14:30', $existing)),
);
Assert::same(
    '2026-05-15 00:00:00',
    $fmt(Resolver::resolveTime('at', '2026-05-15', $existing)),
);

// --- empty / missing ---------------------------------------------------------

Assert::exception(
    static fn() => Resolver::resolveTime('at', null, $existing),
    RuntimeException::class,
    'at: missing <time>',
);
Assert::exception(
    static fn() => Resolver::resolveTime('at', '', $existing),
    RuntimeException::class,
    'at: missing <time>',
);

// --- garbage -----------------------------------------------------------------

Assert::exception(
    static fn() => Resolver::resolveTime('at', 'garbage', $existing),
    RuntimeException::class,
    "at: invalid time 'garbage'",
);

// --- the <cmd>: prefix is preserved in error messages ------------------------

Assert::exception(
    static fn() => Resolver::resolveTime('before', null, $existing),
    RuntimeException::class,
    'before: missing <time>',
);
Assert::exception(
    static fn() => Resolver::resolveTime('after', 'nope', $existing),
    RuntimeException::class,
    "after: invalid time 'nope'",
);
<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Resolver;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

// --- single units ------------------------------------------------------------

Assert::same(30,   Resolver::parseOffset('before', '30m'));
Assert::same(60,   Resolver::parseOffset('before', '1h'));
Assert::same(120,  Resolver::parseOffset('before', '2h'));
Assert::same(1440, Resolver::parseOffset('before', '1d'));
Assert::same(2880, Resolver::parseOffset('before', '2d'));

// --- combined units (canonical: with space) ----------------------------------

Assert::same(80,   Resolver::parseOffset('before', '1h 20m'));
Assert::same(150,  Resolver::parseOffset('before', '2h 30m'));
Assert::same(1500, Resolver::parseOffset('before', '1d 1h'));
Assert::same(1695, Resolver::parseOffset('before', '1d 4h 15m'));

// --- combined units (no space, mixed whitespace) -----------------------------

Assert::same(90,   Resolver::parseOffset('before', '1h30m'));
Assert::same(1500, Resolver::parseOffset('before', '1d1h'));
Assert::same(1695, Resolver::parseOffset('before', '1d4h15m'));
Assert::same(60,   Resolver::parseOffset('before', '  1h  '));
Assert::same(80,   Resolver::parseOffset('before', "1h\t20m"));

// --- case-insensitive --------------------------------------------------------

Assert::same(60,   Resolver::parseOffset('before', '1H'));
Assert::same(80,   Resolver::parseOffset('before', '1H 20M'));
Assert::same(1695, Resolver::parseOffset('before', '1D 4h 15M'));

// --- empty / missing ---------------------------------------------------------

Assert::exception(
    static fn() => Resolver::parseOffset('before', null),
    RuntimeException::class,
    'before: missing <offset>',
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', ''),
    RuntimeException::class,
    'before: missing <offset>',
);

// --- zero offset rejected ----------------------------------------------------

Assert::exception(
    static fn() => Resolver::parseOffset('before', '0m'),
    RuntimeException::class,
    "#must be > 0#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', '0h'),
    RuntimeException::class,
    "#must be > 0#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', '0d 0h 0m'),
    RuntimeException::class,
    "#must be > 0#",
);

// --- garbage / wrong format --------------------------------------------------

Assert::exception(
    static fn() => Resolver::parseOffset('before', 'abc'),
    RuntimeException::class,
    "#invalid offset 'abc'#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', '5'),
    RuntimeException::class,
    "#invalid offset '5'#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', '1x'),
    RuntimeException::class,
    "#invalid offset '1x'#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', '1y'),
    RuntimeException::class,
    "#invalid offset '1y'#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', 'h'),
    RuntimeException::class,
    "#invalid offset 'h'#",
);

// Components must appear in d-h-m order; reverse order is rejected.
Assert::exception(
    static fn() => Resolver::parseOffset('before', '20m 1h'),
    RuntimeException::class,
    "#invalid offset '20m 1h'#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('before', '1h 1d'),
    RuntimeException::class,
    "#invalid offset '1h 1d'#",
);

// --- the <cmd>: prefix is preserved in error messages ------------------------

Assert::exception(
    static fn() => Resolver::parseOffset('after', 'abc'),
    RuntimeException::class,
    "#^after: invalid offset 'abc'#",
);
Assert::exception(
    static fn() => Resolver::parseOffset('after', null),
    RuntimeException::class,
    'after: missing <offset>',
);
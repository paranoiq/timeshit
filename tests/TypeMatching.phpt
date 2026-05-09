<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Resolver;
use Timeshit\Youtrack\WorkItemType;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();

// A representative type list: all 6 allowed types interleaved with several
// disallowed ones (some of which start with prefixes that would otherwise
// have been ambiguous against the allowed set, e.g. Installation/Investigation
// vs Implementation; Internal Meetings vs the comma-bearing allowed name).
$types = [
    new WorkItemType('1',  'Implementation'),                  // allowed
    new WorkItemType('2',  'Installation'),                    // not allowed
    new WorkItemType('3',  'Investigation'),                   // not allowed
    new WorkItemType('4',  'Internal Meetings'),               // not allowed
    new WorkItemType('5',  'Internal Tooling'),                // not allowed
    new WorkItemType('6',  'Out of office'),                   // allowed
    new WorkItemType('7',  'Oprava'),                          // not allowed
    new WorkItemType('8',  'Documentation'),                   // allowed
    new WorkItemType('9',  'Test / Review'),                   // allowed
    new WorkItemType('10', 'Testing'),                         // not allowed
    new WorkItemType('11', 'Analyses / Design'),               // allowed
    new WorkItemType('12', 'Communication, Meetings, ...'),    // allowed
    new WorkItemType('13', 'Čas na cestě'),                    // not allowed
];

// --- exact case-insensitive match against allowed types only ----------------

Assert::same('Implementation',               Resolver::matchType('cmd', 'Implementation', $types));
Assert::same('Implementation',               Resolver::matchType('cmd', 'implementation', $types));
Assert::same('Implementation',               Resolver::matchType('cmd', 'IMPLEMENTATION', $types));
Assert::same('Out of office',                Resolver::matchType('cmd', 'out of office', $types));
Assert::same('Documentation',                Resolver::matchType('cmd', 'Documentation', $types));
Assert::same('Test / Review',                Resolver::matchType('cmd', 'Test / Review', $types));
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'analyses / design', $types));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'Communication, Meetings, ...', $types));

// --- unique case-insensitive prefix on allowed types ------------------------

// Each allowed type starts with a unique letter, so single-letter prefixes
// are always unique.
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'a',    $types));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'c',    $types));
Assert::same('Documentation',                Resolver::matchType('cmd', 'd',    $types));
Assert::same('Implementation',               Resolver::matchType('cmd', 'i',    $types));
Assert::same('Out of office',                Resolver::matchType('cmd', 'o',    $types));
Assert::same('Test / Review',                Resolver::matchType('cmd', 't',    $types));

// Longer prefixes also resolve.
Assert::same('Implementation', Resolver::matchType('cmd', 'imp',  $types));
Assert::same('Implementation', Resolver::matchType('cmd', 'IMPL', $types));
Assert::same('Out of office',  Resolver::matchType('cmd', 'ou',   $types));
Assert::same('Documentation',  Resolver::matchType('cmd', 'doc',  $types));
Assert::same('Test / Review',  Resolver::matchType('cmd', 'test', $types));

// --- disallowed types are invisible to matching -----------------------------

Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Installation', $types),
    RuntimeException::class,
    "#unknown type 'Installation'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Investigation', $types),
    RuntimeException::class,
    "#unknown type 'Investigation'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'inst', $types),
    RuntimeException::class,
    "#unknown type 'inst'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'inv', $types),
    RuntimeException::class,
    "#unknown type 'inv'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'internal', $types),
    RuntimeException::class,
    "#unknown type 'internal'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Čas na cestě', $types),
    RuntimeException::class,
    "#unknown type 'Čas na cestě'#",
);

// --- unknown error lists allowed types only ---------------------------------

Assert::exception(
    static fn() => Resolver::matchType('day', 'xyz', $types),
    RuntimeException::class,
    "#day: unknown type 'xyz'\\. Allowed: Implementation, Out of office, Documentation, Test / Review, Analyses / Design, Communication, Meetings, \\.\\.\\.#",
);

// --- cmd name surfaces in error messages ------------------------------------

Assert::exception(
    static fn() => Resolver::matchType('track', 'inv', $types),
    RuntimeException::class,
    "#^track: unknown type 'inv'#",
);
Assert::exception(
    static fn() => Resolver::matchType('switch', 'inv', $types),
    RuntimeException::class,
    "#^switch: unknown type 'inv'#",
);

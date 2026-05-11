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

$allowed = [
    'Implementation',
    'Out of office',
    'Documentation',
    'Test / Review',
    'Analyses / Design',
    'Communication, Meetings, ...',
];

// --- exact case-insensitive match against allowed types only ----------------

Assert::same('Implementation',               Resolver::matchType('cmd', 'Implementation', $types, $allowed));
Assert::same('Implementation',               Resolver::matchType('cmd', 'implementation', $types, $allowed));
Assert::same('Implementation',               Resolver::matchType('cmd', 'IMPLEMENTATION', $types, $allowed));
Assert::same('Out of office',                Resolver::matchType('cmd', 'out of office', $types, $allowed));
Assert::same('Documentation',                Resolver::matchType('cmd', 'Documentation', $types, $allowed));
Assert::same('Test / Review',                Resolver::matchType('cmd', 'Test / Review', $types, $allowed));
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'analyses / design', $types, $allowed));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'Communication, Meetings, ...', $types, $allowed));

// --- unique case-insensitive prefix on allowed types ------------------------

// Each allowed type starts with a unique letter, so single-letter prefixes
// are always unique.
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'a',    $types, $allowed));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'c',    $types, $allowed));
Assert::same('Documentation',                Resolver::matchType('cmd', 'd',    $types, $allowed));
Assert::same('Implementation',               Resolver::matchType('cmd', 'i',    $types, $allowed));
Assert::same('Out of office',                Resolver::matchType('cmd', 'o',    $types, $allowed));
Assert::same('Test / Review',                Resolver::matchType('cmd', 't',    $types, $allowed));

// Longer prefixes also resolve.
Assert::same('Implementation', Resolver::matchType('cmd', 'imp',  $types, $allowed));
Assert::same('Implementation', Resolver::matchType('cmd', 'IMPL', $types, $allowed));
Assert::same('Out of office',  Resolver::matchType('cmd', 'ou',   $types, $allowed));
Assert::same('Documentation',  Resolver::matchType('cmd', 'doc',  $types, $allowed));
Assert::same('Test / Review',  Resolver::matchType('cmd', 'test', $types, $allowed));

// --- disallowed types are invisible to matching -----------------------------

Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Installation', $types, $allowed),
    RuntimeException::class,
    "#unknown type 'Installation'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Investigation', $types, $allowed),
    RuntimeException::class,
    "#unknown type 'Investigation'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'inst', $types, $allowed),
    RuntimeException::class,
    "#unknown type 'inst'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'inv', $types, $allowed),
    RuntimeException::class,
    "#unknown type 'inv'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'internal', $types, $allowed),
    RuntimeException::class,
    "#unknown type 'internal'#",
);
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Čas na cestě', $types, $allowed),
    RuntimeException::class,
    "#unknown type 'Čas na cestě'#",
);

// --- unknown error lists allowed types only ---------------------------------

Assert::exception(
    static fn() => Resolver::matchType('day', 'xyz', $types, $allowed),
    RuntimeException::class,
    "#day: unknown type 'xyz'\\. Allowed: Implementation, Out of office, Documentation, Test / Review, Analyses / Design, Communication, Meetings, \\.\\.\\.#",
);

// --- cmd name surfaces in error messages ------------------------------------

Assert::exception(
    static fn() => Resolver::matchType('track', 'inv', $types, $allowed),
    RuntimeException::class,
    "#^track: unknown type 'inv'#",
);
Assert::exception(
    static fn() => Resolver::matchType('switch', 'inv', $types, $allowed),
    RuntimeException::class,
    "#^switch: unknown type 'inv'#",
);

// --- aliases ----------------------------------------------------------------

$aliases = [
    'Analyses / Design' => ['Design'],
    'Communication, Meetings, ...' => ['Meeting'],
    'Test / Review' => ['Review'],
];

// Exact alias match (case-insensitive) resolves to the canonical name.
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'Design',  $types, $allowed, $aliases));
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'design',  $types, $allowed, $aliases));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'Meeting', $types, $allowed, $aliases));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'MEETING', $types, $allowed, $aliases));
Assert::same('Test / Review',                Resolver::matchType('cmd', 'Review',  $types, $allowed, $aliases));

// Prefix of an alias resolves to its canonical.
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'm',     $types, $allowed, $aliases));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'mee',   $types, $allowed, $aliases));
Assert::same('Communication, Meetings, ...', Resolver::matchType('cmd', 'meet',  $types, $allowed, $aliases));
Assert::same('Test / Review',                Resolver::matchType('cmd', 'r',     $types, $allowed, $aliases));
Assert::same('Test / Review',                Resolver::matchType('cmd', 'rev',   $types, $allowed, $aliases));
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'des',   $types, $allowed, $aliases));
Assert::same('Analyses / Design',            Resolver::matchType('cmd', 'design', $types, $allowed, $aliases));

// A prefix that hits both a canonical and an alias of distinct types is ambiguous.
// 'd' now matches both Documentation (canonical) and Design (alias → Analyses / Design).
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'd', $types, $allowed, $aliases),
    RuntimeException::class,
    "#ambiguous type 'd'#",
);

// A prefix that hits the same canonical via multiple labels (canonical + alias) is NOT ambiguous.
// 'an' matches only 'Analyses / Design' (the alias 'Design' doesn't start with 'an').
Assert::same('Analyses / Design', Resolver::matchType('cmd', 'an', $types, $allowed, $aliases));

// Unknown error message lists aliases alongside their canonical.
Assert::exception(
    static fn() => Resolver::matchType('day', 'xyz', $types, $allowed, $aliases),
    RuntimeException::class,
    "#day: unknown type 'xyz'\\. Allowed: Implementation, Out of office, Documentation, Test / Review \\(Review\\), Analyses / Design \\(Design\\), Communication, Meetings, \\.\\.\\. \\(Meeting\\)#",
);

// Aliases of disallowed types are invisible — alias map entries pointing at disallowed
// types are still recorded in the alias array but matching only sees the allowed slice.
$narrow = ['Implementation', 'Documentation'];
Assert::exception(
    static fn() => Resolver::matchType('cmd', 'Design', $types, $narrow, $aliases),
    RuntimeException::class,
    "#unknown type 'Design'#",
);

// Exact canonical match still wins over any kind of prefix match.
Assert::same('Documentation', Resolver::matchType('cmd', 'Documentation', $types, $allowed, $aliases));

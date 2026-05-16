<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === at (set absolute time on the last non-day record) ===

// 1. on an open record, `at HH:MM` sets startedAt (keeps the date) — needs `y` to confirm;
//    the edit is appended to the record's log with the original value
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'at', '09:30']));
$items = $store->load();
Assert::same('2026-05-09 09:30', $items[0]->startedAt);
Assert::contains(
    'edited startedAt from 2026-05-09 10:00 to 2026-05-09 09:30 at 2026-05-09 10:30 (at)',
    $items[0]->log,
);
Assert::null($items[0]->endedAt);

// 2. on a closed record, `at HH:MM` sets endedAt
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'at', '14:00']));
$items = $store->load();
Assert::same('2026-05-09 14:00', $items[0]->endedAt);
Assert::contains(
    'edited endedAt from 2026-05-09 11:00 to 2026-05-09 14:00 at 2026-05-09 11:00 (at)',
    $items[0]->log,
);

// 3. anything other than `y` cancels the change
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$snapshot = $store->load();
$io->setInputs(['n']);
Assert::same(0, $app->run(['ts', 'at', '09:30']));
Assert::equal($snapshot, $store->load());
Assert::contains('Cancelled.', $io->getErr());

// 4. setting the new time equal to the old one is a no-op (no confirm needed)
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$snapshot = $store->load();
Assert::same(0, $app->run(['ts', 'at', '10:00']));
Assert::equal($snapshot, $store->load());
Assert::contains('No change.', $io->getErr());

// 5. shifting endedAt to a value at-or-before startedAt errors (non-positive duration)
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$io->setInputs(['y']);
Assert::same(1, $app->run(['ts', 'at', '09:30']));
Assert::contains('non-positive duration', $io->getErr());
$items = $store->load();
Assert::same('2026-05-09 11:00', $items[0]->endedAt);


// === before (shift the last record's relevant timestamp earlier) ===

// 6. before on an open record shifts startedAt earlier; log records the from→to
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'before', '1h']));
$items = $store->load();
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::contains(
    'edited startedAt from 2026-05-09 10:00 to 2026-05-09 09:00 at 2026-05-09 10:30 (before)',
    $items[0]->log,
);

// 7. a second before further shifts startedAt; log keeps both edits in order
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->setInputs(['y']);
$app->run(['ts', 'before', '1h']);
$io->setInputs(['y']);
$app->run(['ts', 'before', '30m']);
$items = $store->load();
Assert::same('2026-05-09 08:30', $items[0]->startedAt);
Assert::contains(
    'edited startedAt from 2026-05-09 10:00 to 2026-05-09 09:00 at 2026-05-09 10:30 (before)',
    $items[0]->log,
);
Assert::contains(
    'edited startedAt from 2026-05-09 09:00 to 2026-05-09 08:30 at 2026-05-09 10:30 (before)',
    $items[0]->log,
);

// 8. adjacency: when before-shifting an open record's startedAt and the prior
//    record's endedAt matches the OLD startedAt, that endedAt is shifted too
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-2']); // closes ABC-1 at 10:30, opens XYZ-2 at 10:30
$clock->advance('+10 minutes');
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'before', '15m']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('2026-05-09 10:15', $items[0]->endedAt);            // prev record's end follows
Assert::contains(
    'edited endedAt from 2026-05-09 10:30 to 2026-05-09 10:15 at 2026-05-09 10:40 (before)',
    $items[0]->log,
);
Assert::same('2026-05-09 10:15', $items[1]->startedAt);          // open record's start
Assert::contains(
    'edited startedAt from 2026-05-09 10:30 to 2026-05-09 10:15 at 2026-05-09 10:40 (before)',
    $items[1]->log,
);

// 9. before on a closed record shifts endedAt earlier
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+2 hours');
$app->run(['ts', 'end']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'before', '30m']));
$items = $store->load();
Assert::same('2026-05-09 11:30', $items[0]->endedAt);
Assert::contains(
    'edited endedAt from 2026-05-09 12:00 to 2026-05-09 11:30 at 2026-05-09 12:00 (before)',
    $items[0]->log,
);

// 10. before with malformed span errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'before', 'garbage']));
Assert::contains("invalid span 'garbage'", $io->getErr());


// === after (shift the last closed record's endedAt later) ===

// 11. after on a closed record shifts endedAt later
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'after', '45m']));
$items = $store->load();
Assert::same('2026-05-09 11:45', $items[0]->endedAt);
Assert::contains(
    'edited endedAt from 2026-05-09 11:00 to 2026-05-09 11:45 at 2026-05-09 11:00 (after)',
    $items[0]->log,
);

// 12. after on an open record errors (use before/at to move its start, or close it first)
[$app, $store, , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'after', '30m']));
Assert::contains('last entry is open', $io->getErr());
Assert::equal($snapshot, $store->load());


// === skip (close at now-span, immediately reopen at now) ===

// 13. skip closes the open record at now-span and opens a clone at now
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
Assert::same(0, $app->run(['ts', 'skip', '15m']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-09 10:00', $items[0]->startedAt);
Assert::same('2026-05-09 10:45', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 10:45 (skip)', $items[0]->log);
Assert::same('ABC-1', $items[1]->issueId);
Assert::same('2026-05-09 11:00', $items[1]->startedAt);
Assert::same('created at 2026-05-09 11:00 (skip)', $items[1]->log);
Assert::null($items[1]->endedAt);

// 14. skip with no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'skip', '15m']));
Assert::contains('no open tracking entry', $io->getErr());

// 15. skip with span >= the open record's duration errors
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->clear();
Assert::same(1, $app->run(['ts', 'skip', '1h']));
Assert::contains('span too large', $io->getErr());
Assert::count(1, $store->load());


// === grab (skip but with a closed record filling the gap) ===

// 16. grab writes three records: closed-original, grabbed, continuation
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+1 hour');
Assert::same(0, $app->run(['ts', 'grab', 'XYZ-9', '20m', 'test']));
$items = $store->load();
Assert::count(3, $items);
// closed original
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Documentation', $items[0]->type);
Assert::same('2026-05-09 10:40', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 10:40 (grab)', $items[0]->log);
// grabbed middle
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Test / Review', $items[1]->type);
Assert::same('2026-05-09 10:40', $items[1]->startedAt);
Assert::same('2026-05-09 11:00', $items[1]->endedAt);
Assert::same(
    'created at 2026-05-09 10:40 (grab) | closed at 2026-05-09 11:00 (grab)',
    $items[1]->log,
);
// continuation
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Documentation', $items[2]->type);
Assert::same('2026-05-09 11:00', $items[2]->startedAt);
Assert::same('created at 2026-05-09 11:00 (grab)', $items[2]->log);
Assert::null($items[2]->endedAt);

// 17. grab default type is Implementation when no type is provided
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+1 hour');
$app->run(['ts', 'grab', 'XYZ-9', '20m']);
Assert::same('Implementation', $store->load()[1]->type);

// 18. grab with unusual issue id is accepted with a warning
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$io->clear();
Assert::same(0, $app->run(['ts', 'grab', 'not-id', '20m']));
$items = $store->load();
Assert::count(3, $items);
Assert::same('not-id', $items[1]->issueId);
Assert::contains('unusual issue id format', $io->getErr());

// 18b. grab with a plain integer expands it with the default prefix
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$io->clear();
Assert::same(0, $app->run(['ts', 'grab', '99', '20m']));
$items = $store->load();
Assert::count(3, $items);
Assert::same('SW-99', $items[1]->issueId);
Assert::notContains('unusual', $io->getErr());

// 19. grab with no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'grab', 'XYZ-9', '20m']));
Assert::contains('no open tracking entry', $io->getErr());

// 20. grab with span >= open record's duration errors
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$io->clear();
Assert::same(1, $app->run(['ts', 'grab', 'XYZ-9', '30m']));
Assert::contains('span too large', $io->getErr());
Assert::count(1, $store->load());


// === at / before / after with explicit #id targeting ===

// 21. at #id targets a closed earlier record (not the latest one)
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'ABC-2']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'at', '#1', '11:00']));
$items = $store->load();
Assert::same('2026-05-09 11:00', $items[0]->endedAt);
Assert::null($items[1]->endedAt);
Assert::same('2026-05-09 11:00', $items[1]->startedAt); // ABC-2 untouched

// 22. before #id shifts an earlier closed record's endedAt
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'ABC-2']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'before', '#1', '15m']));
$items = $store->load();
Assert::same('2026-05-09 10:45', $items[0]->endedAt);

// 23. after #id shifts an earlier closed record's endedAt forward
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'ABC-2']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'after', '#1', '15m']));
$items = $store->load();
Assert::same('2026-05-09 11:15', $items[0]->endedAt);

// 24. at #id with unknown id errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'at', '#99', '09:30']));
Assert::contains('entry #99 not found', $io->getErr());
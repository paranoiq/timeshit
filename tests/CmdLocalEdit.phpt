<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Local\Record;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === at (set absolute time on the last non-day record) ===

// 1. on an open record, `at HH:MM` sets startedAt (keeps the date) — needs `y` to confirm
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'at', '09:30']));
$items = $store->load();
Assert::same('2026-05-09 09:30', $items[0]->startedAt);
Assert::same('2026-05-09 10:00', $items[0]->origStartedAt);
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
Assert::same('2026-05-09 11:00', $items[0]->origEndedAt);

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

// 6. before on an open record shifts startedAt earlier and captures origStartedAt
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'before', '1h']));
$items = $store->load();
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 10:00', $items[0]->origStartedAt);

// 7. a second before further shifts startedAt but does NOT overwrite origStartedAt
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->setInputs(['y']);
$app->run(['ts', 'before', '1h']);
$io->setInputs(['y']);
$app->run(['ts', 'before', '30m']);
$items = $store->load();
Assert::same('2026-05-09 08:30', $items[0]->startedAt);
Assert::same('2026-05-09 10:00', $items[0]->origStartedAt); // still the very first value

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
Assert::same('2026-05-09 10:30', $items[0]->origEndedAt);        // origEndedAt captured
Assert::same('2026-05-09 10:15', $items[1]->startedAt);          // open record's start
Assert::same('2026-05-09 10:30', $items[1]->origStartedAt);

// 9. before on a closed record shifts endedAt earlier
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+2 hours');
$app->run(['ts', 'end']);
$io->setInputs(['y']);
Assert::same(0, $app->run(['ts', 'before', '30m']));
$items = $store->load();
Assert::same('2026-05-09 11:30', $items[0]->endedAt);
Assert::same('2026-05-09 12:00', $items[0]->origEndedAt);

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
Assert::same('2026-05-09 11:00', $items[0]->origEndedAt);

// 12. after on an open record errors (use before/at to move its start, or close it first)
[$app, $store, , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'after', '30m']));
Assert::contains('last record is open', $io->getErr());
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
Assert::same('skipped', $items[0]->endTrigger);
Assert::same('ABC-1', $items[1]->issueId);
Assert::same('2026-05-09 11:00', $items[1]->startedAt);
Assert::same('skipped', $items[1]->startTrigger);
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
Assert::same('grabbed', $items[0]->endTrigger);
// grabbed middle
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Test / Review', $items[1]->type);
Assert::same('2026-05-09 10:40', $items[1]->startedAt);
Assert::same('2026-05-09 11:00', $items[1]->endedAt);
Assert::same('grabbed', $items[1]->startTrigger);
Assert::same('grabbed', $items[1]->endTrigger);
Assert::same('', $items[1]->repo);
Assert::null($items[1]->branch);
// continuation
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Documentation', $items[2]->type);
Assert::same('2026-05-09 11:00', $items[2]->startedAt);
Assert::same('grabbed', $items[2]->startTrigger);
Assert::null($items[2]->endedAt);

// 17. grab default type is Implementation when no type is provided
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+1 hour');
$app->run(['ts', 'grab', 'XYZ-9', '20m']);
Assert::same('Implementation', $store->load()[1]->type);

// 18. grab with invalid issue rejects
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'grab', 'not-id', '20m']));
Assert::contains("invalid issue 'not-id'", $io->getErr());
Assert::equal($snapshot, $store->load());

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
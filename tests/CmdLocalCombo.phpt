<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === combination scenario 1: track → pause → resume → end ===
//
// Two segments on the same issue, separated by an untracked break record
// produced by `pause`. Resume closes the break and opens a fresh segment
// cloned from the paused tracking record; end closes that segment.
// Yields three records: paused tracking, closed break, ended-resumed segment.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');             // 10:00
$app->run(['ts', 'pause']);
$clock->advance('+30 minutes');         // 10:30
$app->run(['ts', 'resume']);
$clock->advance('+45 minutes');         // 11:15
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(3, $items);
// 1. paused tracking
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 10:00', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 10:00 (pause)', $items[0]->log);
Assert::same('paused', $items[0]->status);
// 2. break — opened by pause, closed by resume
Assert::same('', $items[1]->issueId);
Assert::same('untracked', $items[1]->status);
Assert::contains('created at 2026-05-09 10:00 (pause)', $items[1]->log);
Assert::same('2026-05-09 10:00', $items[1]->startedAt);
Assert::same('2026-05-09 10:30', $items[1]->endedAt);
Assert::contains('closed at 2026-05-09 10:30 (resume)', $items[1]->log);
// 3. resumed segment — cloned from ABC-1, closed by end
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('2026-05-09 10:30', $items[2]->startedAt);
Assert::contains('created at 2026-05-09 10:30 (resume)', $items[2]->log);
Assert::same('2026-05-09 11:15', $items[2]->endedAt);
Assert::contains('closed at 2026-05-09 11:15 (end)', $items[2]->log);


// === combination scenario 2: track → switch type → end (multi-type segments) ===
//
// Same issue, two segments with different types — what `switch` is for. End
// closes the second segment.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'switch', 'doc']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('Implementation', $items[0]->type);
Assert::contains('closed at 2026-05-09 10:00 (switch)', $items[0]->log);
Assert::same('Documentation', $items[1]->type);
Assert::contains('created at 2026-05-09 10:00 (switch)', $items[1]->log);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('ABC-1', $items[1]->issueId);
Assert::same($items[0]->endedAt, $items[1]->startedAt); // adjacent


// === combination scenario 3: track → end → track → before with adjacency ===
//
// First record closes at 10:00, second opens at 10:00. Shifting the open
// record's startedAt earlier by 30m must also drag the previous record's
// endedAt back to 09:30 (adjacency rule).
[$app, $store, $clock, $io] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');                 // 10:00
$app->run(['ts', 'track', 'XYZ-2']);        // closes ABC-1 @ 10:00, opens XYZ-2 @ 10:00
$clock->advance('+10 minutes');             // 10:10
$io->setInputs(['y']);
$app->run(['ts', 'before', '30m']);         // shift XYZ-2 startedAt to 09:30
$items = $store->load();
Assert::count(2, $items);
Assert::same('2026-05-09 09:30', $items[0]->endedAt);   // adjacency dragged the prior close back
Assert::contains(
    'edited endedAt from 2026-05-09 10:00 to 2026-05-09 09:30 at 2026-05-09 10:10 (before)',
    $items[0]->log,
);
Assert::same('2026-05-09 09:30', $items[1]->startedAt);
Assert::contains(
    'edited startedAt from 2026-05-09 10:00 to 2026-05-09 09:30 at 2026-05-09 10:10 (before)',
    $items[1]->log,
);


// === combination scenario 4: track → note → switch (note placement) ===
//
// Note lands on the first segment; switching opens a second segment with no
// note. Ensures `note` targets the open record at the time it runs, not
// future segments.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$app->run(['ts', 'note', 'design', 'note']);
$clock->advance('+30 minutes');
$app->run(['ts', 'switch', 'doc']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('design note', $items[0]->note);
Assert::same('', $items[1]->note);


// === combination scenario 5: track → grab → end — interruption flow ===
//
// User forgot to track an interruption: they were on ABC-1 from 09:00, but
// 20 minutes ago jumped to XYZ-9 instead. `grab` reconstructs all three
// segments, then `end` closes the resumed continuation.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');                // 10:00
$app->run(['ts', 'grab', 'XYZ-9', '20m']); // 09:40 split, XYZ-9 till 10:00, ABC-1 continues
$clock->advance('+15 minutes');            // 10:15
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(3, $items);
// closed original
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 09:40', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 09:40 (grab)', $items[0]->log);
// grabbed middle
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('2026-05-09 09:40', $items[1]->startedAt);
Assert::same('2026-05-09 10:00', $items[1]->endedAt);
// continuation, now closed by end
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('2026-05-09 10:00', $items[2]->startedAt);
Assert::same('2026-05-09 10:15', $items[2]->endedAt);
Assert::contains('closed at 2026-05-09 10:15 (end)', $items[2]->log);


// === combination scenario 6: end → note lands on the most recent closed ===
//
// `note` should attach to the latest non-day record whether open or closed.
// After `end`, no record is open, so the note goes to the freshly-closed
// record — useful for recording "wrap-up notes" after the fact.
[$app, $store, $clock, $io] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$io->clear();
$app->run(['ts', 'note', 'forgot', 'to', 'note']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('forgot to note', $items[0]->note);
Assert::contains('Note on', $io->getErr());
Assert::contains('ABC-1', $io->getErr());
Assert::contains('(last closed)', $io->getErr());


// === combination scenario 7: at after end, then track new — edit log persists ===
//
// Editing endedAt with `at` appends an `edited endedAt from ... to ...` entry to
// the closed record's log. Subsequent commands (like `track`) write a new record
// and must not disturb the prior record's log.
[$app, $store, $clock, $io] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');                 // 10:00
$app->run(['ts', 'end']);                   // ABC-1 closed @ 10:00
$io->setInputs(['y']);
$app->run(['ts', 'at', '11:00']);           // shift endedAt to 11:00
$clock->advance('+90 minutes');             // 11:30
$app->run(['ts', 'track', 'XYZ-2']);        // opens new — must not touch ABC-1's log
$items = $store->load();
Assert::count(2, $items);
Assert::same('2026-05-09 11:00', $items[0]->endedAt);
Assert::contains(
    'edited endedAt from 2026-05-09 10:00 to 2026-05-09 11:00 at 2026-05-09 10:00 (at)',
    $items[0]->log,
);
Assert::same('XYZ-2', $items[1]->issueId);
Assert::same('2026-05-09 11:30', $items[1]->startedAt);


// === combination scenario 8: track → before → end → at — log accumulates both edits ===
//
// A record can accumulate both startedAt and endedAt edits across separate edit
// commands. `before` rewrites the open record's startedAt; `end` closes it;
// `at` rewrites endedAt. All three entries land in the log alongside the
// original `created` and `closed` entries.
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
Assert::same('2026-05-09 10:00', $store->load()[0]->startedAt);

$clock->advance('+30 minutes');             // 10:30
$io->setInputs(['y']);
$app->run(['ts', 'before', '1h']);          // open record's startedAt: 10:00 → 09:00
Assert::same('2026-05-09 09:00', $store->load()[0]->startedAt);

$clock->advance('+30 minutes');             // 11:00
$app->run(['ts', 'end']);                   // closes at 11:00
Assert::same('2026-05-09 11:00', $store->load()[0]->endedAt);

$io->setInputs(['y']);
$app->run(['ts', 'at', '14:00']);           // closed record's endedAt: 11:00 → 14:00
$items = $store->load();
Assert::count(1, $items);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 14:00', $items[0]->endedAt);
Assert::contains(
    'edited startedAt from 2026-05-09 10:00 to 2026-05-09 09:00 at 2026-05-09 10:30 (before)',
    $items[0]->log,
);
Assert::contains(
    'edited endedAt from 2026-05-09 11:00 to 2026-05-09 14:00 at 2026-05-09 11:00 (at)',
    $items[0]->log,
);
$err = $io->getErr();
Assert::contains('Tracking', $err);
Assert::contains('Stopped', $err);
Assert::contains('ABC-1', $err);
Assert::contains('Saved.', $err);
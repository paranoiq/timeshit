<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === combination scenario 1: track → pause → resume → end ===
//
// Two open segments on the same issue, separated by a pause, joined back by
// resume, then closed. Yields two records: the paused segment and the
// resumed-then-ended segment.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');             // 10:00
$app->run(['ts', 'pause']);
$clock->advance('+30 minutes');         // 10:30 (gap)
$app->run(['ts', 'resume']);
$clock->advance('+45 minutes');         // 11:15
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 10:00', $items[0]->endedAt);
Assert::same('paused', $items[0]->endTrigger);
Assert::same('ABC-1', $items[1]->issueId);
Assert::same('2026-05-09 10:30', $items[1]->startedAt);
Assert::same('resumed', $items[1]->startTrigger);
Assert::same('2026-05-09 11:15', $items[1]->endedAt);
Assert::same('ended', $items[1]->endTrigger);


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
Assert::same('switched', $items[0]->endTrigger);
Assert::same('Documentation', $items[1]->type);
Assert::same('switched', $items[1]->startTrigger);
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
Assert::same('2026-05-09 10:00', $items[0]->origEndedAt);
Assert::same('2026-05-09 09:30', $items[1]->startedAt);
Assert::same('2026-05-09 10:00', $items[1]->origStartedAt);


// === combination scenario 4: track → comment → switch (comment placement) ===
//
// Comment lands on the first segment; switching opens a second segment with no
// comment. Ensures `comment` targets the open record at the time it runs, not
// future segments.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$app->run(['ts', 'comment', 'design', 'note']);
$clock->advance('+30 minutes');
$app->run(['ts', 'switch', 'doc']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('design note', $items[0]->comment);
Assert::same('', $items[1]->comment);


// === combination scenario 5: track → steal → end — interruption flow ===
//
// User forgot to track an interruption: they were on ABC-1 from 09:00, but
// 20 minutes ago jumped to XYZ-9 instead. `steal` reconstructs all three
// segments, then `end` closes the resumed continuation.
[$app, $store, $clock] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');                 // 10:00
$app->run(['ts', 'steal', 'XYZ-9', '20m']); // 09:40 split, XYZ-9 till 10:00, ABC-1 continues
$clock->advance('+15 minutes');             // 10:15
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(3, $items);
// closed original
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 09:40', $items[0]->endedAt);
Assert::same('stolen', $items[0]->endTrigger);
// stolen middle
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('2026-05-09 09:40', $items[1]->startedAt);
Assert::same('2026-05-09 10:00', $items[1]->endedAt);
// continuation, now closed by end
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('2026-05-09 10:00', $items[2]->startedAt);
Assert::same('2026-05-09 10:15', $items[2]->endedAt);
Assert::same('ended', $items[2]->endTrigger);


// === combination scenario 6: end → comment lands on the most recent closed ===
//
// `comment` should attach to the latest non-day record whether open or closed.
// After `end`, no record is open, so the comment goes to the freshly-closed
// record — useful for recording "wrap-up notes" after the fact.
[$app, $store, $clock, $io] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end']);
$io->clear();
$app->run(['ts', 'comment', 'forgot', 'to', 'note']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('forgot to note', $items[0]->comment);
Assert::contains('Comment on ABC-1', $io->getErr());
Assert::contains('(last closed)', $io->getErr());


// === combination scenario 7: at after end, then track new (origEndedAt persists) ===
//
// Editing endedAt with `at` must capture origEndedAt; subsequent commands like
// `track` (which writes new records) must not disturb that value.
[$app, $store, $clock, $io] = newApp('2026-05-09 09:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');                 // 10:00
$app->run(['ts', 'end']);                   // ABC-1 closed @ 10:00
$io->setInputs(['y']);
$app->run(['ts', 'at', '11:00']);           // shift endedAt to 11:00
$clock->advance('+90 minutes');             // 11:30
$app->run(['ts', 'track', 'XYZ-2']);        // opens new — must not touch ABC-1's origEndedAt
$items = $store->load();
Assert::count(2, $items);
Assert::same('2026-05-09 11:00', $items[0]->endedAt);
Assert::same('2026-05-09 10:00', $items[0]->origEndedAt);
Assert::same('XYZ-2', $items[1]->issueId);
Assert::same('2026-05-09 11:30', $items[1]->startedAt);


// === combination scenario 8: track → before → end → at — both orig fields on one record ===
//
// A record can accumulate both origStartedAt and origEndedAt across separate
// edit commands: `before` rewrites the open record's startedAt (capturing
// origStartedAt), `end` closes it, then `at` rewrites endedAt (capturing
// origEndedAt). The captured first-recorded values persist independently.
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
Assert::same('2026-05-09 10:00', $store->load()[0]->startedAt);

$clock->advance('+30 minutes');             // 10:30
$io->setInputs(['y']);
$app->run(['ts', 'before', '1h']);          // open record's startedAt: 10:00 → 09:00
Assert::same('2026-05-09 09:00', $store->load()[0]->startedAt);
Assert::same('2026-05-09 10:00', $store->load()[0]->origStartedAt);

$clock->advance('+30 minutes');             // 11:00
$app->run(['ts', 'end']);                   // closes at 11:00
Assert::same('2026-05-09 11:00', $store->load()[0]->endedAt);

$io->setInputs(['y']);
$app->run(['ts', 'at', '14:00']);           // closed record's endedAt: 11:00 → 14:00
$items = $store->load();
Assert::count(1, $items);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);
Assert::same('2026-05-09 14:00', $items[0]->endedAt);
Assert::same('2026-05-09 10:00', $items[0]->origStartedAt);
Assert::same('2026-05-09 11:00', $items[0]->origEndedAt);
$err = $io->getErr();
Assert::contains('Tracking ABC-1', $err);
Assert::contains('Stopped ABC-1', $err);
Assert::contains('Saved.', $err);
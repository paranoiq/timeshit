<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === track ===

// 1. track a new issue with the default type (Implementation)
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'track', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 10:00', $items[0]->startedAt);
Assert::null($items[0]->endedAt);
Assert::same('new', $items[0]->status);
Assert::same('created at 2026-05-09 10:00 (track)', $items[0]->log);

// 2. issue id is uppercased, even when typed in lower case
[$app, $store] = newApp();
$app->run(['ts', 'track', 'abc-1']);
Assert::same('ABC-1', $store->load()[0]->issueId);

// 3. explicit type via case-insensitive prefix match
[$app, $store] = newApp();
Assert::same(0, $app->run(['ts', 'track', 'ABC-1', 'doc']));
Assert::same('Documentation', $store->load()[0]->type);

// 4. tracking the same issue+type while already open is a no-op
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$snapshot = $store->load();
$clock->advance('+10 minutes');
Assert::same(0, $app->run(['ts', 'track', 'ABC-1']));
Assert::equal($snapshot, $store->load());

// 5. tracking a different issue closes the prior open record at the new startedAt
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'track', 'XYZ-2']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 10:30 (track)', $items[0]->log);
Assert::same('XYZ-2', $items[1]->issueId);
Assert::same('2026-05-09 10:30', $items[1]->startedAt);
Assert::null($items[1]->endedAt);

// 6. tracking the same issue but a different type also rolls the open record
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 10:15', $items[0]->endedAt);
Assert::same('Documentation', $items[1]->type);

// 7. unusual issue id is accepted with a warning
[$app, $store, , $io] = newApp();
Assert::same(0, $app->run(['ts', 'track', 'not-an-id']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('not-an-id', $items[0]->issueId);
Assert::contains('unusual issue id format', $io->getErr());

// 7b. plain integer is expanded with the configured default prefix
[$app, $store, , $io] = newApp();
Assert::same(0, $app->run(['ts', 'track', '42']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-42', $items[0]->issueId);
Assert::notContains('unusual', $io->getErr());

// 8. missing issue is rejected
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'track']));
Assert::contains('missing <issue>', $io->getErr());

// 9. unknown type is rejected; nothing is written
[$app, $store, , $io] = newApp();
Assert::same(1, $app->run(['ts', 'track', 'ABC-1', 'nonsense']));
Assert::same([], $store->load());
Assert::contains("unknown type 'nonsense'", $io->getErr());


// === end ===

// 10. end closes the open record and logs the `(end)` trigger
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
Assert::same(0, $app->run(['ts', 'end']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('2026-05-09 11:00', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 11:00 (end)', $items[0]->log);

// 11. end on no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'end']));
Assert::contains('no open tracking entry', $io->getErr());

// 12. end with a comment merges the text into the closed record
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+1 hour');
$app->run(['ts', 'end', 'wrapped', 'up', 'feature']);
Assert::same('wrapped up feature', $store->load()[0]->note);

// 13. end on a record that already has a comment joins them with " | "
$preset = [new Timeshit\Local\Record(
    id: 1,
    issueId: 'ABC-1',
    type: 'Implementation',
    startedAt: '2026-05-09 09:00',
    endedAt: null,
    log: 'created at 2026-05-09 09:00 (track)',
    note: 'existing',
)];
[$app, $store, $clock] = newApp('2026-05-09 10:00', $preset);
$clock->advance('+1 hour');
$app->run(['ts', 'end', 'and-more']);
Assert::same('existing | and-more', $store->load()[0]->note);


// === pause ===

// 14. pause closes the active tracking record (logged with `(pause)`,
//     status='paused') and opens a new break record (status='untracked');
//     the comment goes on the break record, not the closed tracking one.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'pause', 'lunch']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::contains('closed at 2026-05-09 10:30 (pause)', $items[0]->log);
Assert::same('paused', $items[0]->status);
Assert::same('', $items[0]->note);                // pause note does NOT append here
Assert::same('', $items[1]->issueId);                // break record has no issue
Assert::same('untracked', $items[1]->status);
Assert::contains('created at 2026-05-09 10:30 (pause)', $items[1]->log);
Assert::null($items[1]->endedAt);
Assert::same('lunch', $items[1]->note);           // pause note lives on the break record

// 15. pause with no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'pause']));
Assert::contains('no open tracking entry', $io->getErr());


// === resume ===

// 16. resume after pause: closes the open break record AND opens a new record
//     cloned from the paused tracking record (issue/type preserved)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+30 minutes');
$app->run(['ts', 'pause']);
$clock->advance('+1 hour');
Assert::same(0, $app->run(['ts', 'resume']));
$items = $store->load();
Assert::count(3, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('paused', $items[0]->status);
Assert::same('untracked', $items[1]->status);        // break, now closed by resume
Assert::same('2026-05-09 11:30', $items[1]->endedAt);
Assert::contains('closed at 2026-05-09 11:30 (resume)', $items[1]->log);
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Documentation', $items[2]->type);
Assert::contains('created at 2026-05-09 11:30 (resume)', $items[2]->log);
Assert::same('2026-05-09 11:30', $items[2]->startedAt);
Assert::null($items[2]->endedAt);

// 17. resume with no records errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'resume']));
Assert::contains('no record to resume', $io->getErr());

// 18. resume errors only when the latest open record is a tracking record;
//     an open *untracked* break record is not an error (resume closes it).
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'resume']));
Assert::contains('a record is already open', $io->getErr());

// 20. double pause is a silent no-op (already paused — same untracked shape, store sees match)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'pause']);
$snapshot = $store->load();
$clock->advance('+5 minutes');
Assert::same(0, $app->run(['ts', 'pause']));
Assert::equal($snapshot, $store->load());
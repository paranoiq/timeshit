<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Local\Record;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === checkout (called by hooks/post-checkout — extracts the issue id from the branch) ===

// 1. checkout extracts the issue id from the branch; logs the `(checkout)` trigger
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'checkout', 'ABC-123-add-widget', 'my-repo']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-123', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('created at 2026-05-09 10:00 (checkout)', $items[0]->log);
Assert::null($items[0]->endedAt);

// 2. branches without an issue prefix become the issue id verbatim
[$app, $store] = newApp();
$app->run(['ts', 'checkout', 'main', 'my-repo']);
Assert::same('main', $store->load()[0]->issueId);

// 3. checkout to the same issue+type is a no-op
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'checkout', 'ABC-1', 'my-repo']);
$snapshot = $store->load();
$clock->advance('+10 minutes');
$app->run(['ts', 'checkout', 'ABC-1', 'my-repo']);
Assert::equal($snapshot, $store->load());

// 4. switching branches closes the prior record and opens a new one
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'checkout', 'ABC-1', 'my-repo']);
$clock->advance('+30 minutes');
$app->run(['ts', 'checkout', 'XYZ-2', 'my-repo']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 10:30 (checkout)', $items[0]->log);
Assert::same('XYZ-2', $items[1]->issueId);

// 5. missing branch / repo are rejected
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'checkout']));
Assert::contains('missing <branch>', $io->getErr());
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'checkout', 'ABC-1']));
Assert::contains('missing <repo>', $io->getErr());


// === days (full 8h record at 09:00–17:00 on chosen days) ===
//
// `days` takes 0–2 date args: 0 = today, 1 = that date, 2 = weekday range
// (inclusive, weekends skipped). Issue + type come from defaultDayIssue /
// defaultDayType (see bootstrap); CLI does not take an `<issue>` / `<type>` slot.

// 6. days with no args logs today as a closed 8h record with defaults + (days) log
[$app, $store] = newApp('2026-05-08 14:30');
Assert::same(0, $app->run(['ts', 'days']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-5070', $items[0]->issueId);         // from defaultDayIssue
Assert::same('Out of office', $items[0]->type);      // from defaultDayType
Assert::same('2026-05-08 09:00', $items[0]->startedAt);
Assert::same('2026-05-08 17:00', $items[0]->endedAt);
Assert::same('day', $items[0]->status);
Assert::same(
    'created at 2026-05-08 09:00 (days) | closed at 2026-05-08 17:00 (days)',
    $items[0]->log,
);

// 7. days <date> logs the chosen day (issue + type still come from defaults)
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'days', '2026-05-08']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-5070', $items[0]->issueId);
Assert::same('2026-05-08 09:00', $items[0]->startedAt);

// 8. days refuses a second full-day on the same calendar date
[$app, $store, , $io] = newApp('2026-05-09 14:30');
$app->run(['ts', 'days', '2026-05-08']);
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'days', '2026-05-08']));
Assert::contains('a full-day entry already exists on 2026-05-08', $io->getErr());
Assert::equal($snapshot, $store->load());

// 9. days inserts the closed record BEFORE any open record (preserves the open-is-latest invariant)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'days', '2026-05-08']);
$items = $store->load();
Assert::count(2, $items);
// day record is at index 0, open record stays at index 1 (latest)
Assert::same('SW-5070', $items[0]->issueId);
Assert::same('day', $items[0]->status);
Assert::same('ABC-1', $items[1]->issueId);
Assert::null($items[1]->endedAt);

// 9a. issue / type / note positional args after the dates (dates peeled by parsing)
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'days', '2026-05-08', 'ABC-1', 'doc', 'sick', 'leave']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Documentation', $items[0]->type);
Assert::same('sick leave', $items[0]->note);

// 9b. without a leading date arg the first slot is the issue (date defaults to today)
[$app, $store] = newApp('2026-05-08 14:30');
Assert::same(0, $app->run(['ts', 'days', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('2026-05-08 09:00', $items[0]->startedAt);

// 9c. two leading dates → range; remaining args become issue + type
[$app, $store] = newApp('2026-05-04 10:00');
Assert::same(0, $app->run(['ts', 'days', '2026-05-04', '2026-05-06', 'ABC-1', 'doc']));
$items = $store->load();
Assert::count(3, $items);
foreach ($items as $r) {
    Assert::same('ABC-1', $r->issueId);
    Assert::same('Documentation', $r->type);
}


// === days <from> <to> — weekday range (inclusive, weekends skipped) ===
//
// Exercised via the `vacation` custom command (parent: days, type: 'Out of office').
// Reference dates: Mon 2026-05-04, Tue 05, Wed 06, Thu 07, Fri 08, Sat 09, Sun 10, Mon 11.
// Log trigger is the custom command name, so entries are tagged `(vacation)`.

// 10c. vacation Mon→Fri produces 5 closed 8h day records, in calendar order
[$app, $store] = newApp('2026-05-04 12:00');
Assert::same(0, $app->run(['ts', 'vacation', '2026-05-04', '2026-05-08']));
$items = $store->load();
Assert::count(5, $items);
$expectedDays = ['2026-05-04', '2026-05-05', '2026-05-06', '2026-05-07', '2026-05-08'];
foreach ($items as $i => $r) {
    Assert::same('SW-5070', $r->issueId);            // from defaultDayIssue
    Assert::same('Out of office', $r->type);         // from the custom command's `type:`
    Assert::same('day', $r->status);
    Assert::same("{$expectedDays[$i]} 09:00", $r->startedAt);
    Assert::same("{$expectedDays[$i]} 17:00", $r->endedAt);
    Assert::contains("created at {$expectedDays[$i]} 09:00 (vacation)", $r->log);
    Assert::contains("closed at {$expectedDays[$i]} 17:00 (vacation)", $r->log);
}

// 10d. vacation Mon→Mon (next week) skips Sat+Sun: 5 + 1 = 6 records
[$app, $store] = newApp('2026-05-04 12:00');
Assert::same(0, $app->run(['ts', 'vacation', '2026-05-04', '2026-05-11']));
$items = $store->load();
Assert::count(6, $items);
$gotDays = array_map(static fn($r) => substr($r->startedAt, 0, 10), $items);
Assert::same(['2026-05-04', '2026-05-05', '2026-05-06', '2026-05-07', '2026-05-08', '2026-05-11'], $gotDays);

// 10e. vacation single-day range on a weekday writes one record
[$app, $store] = newApp('2026-05-04 12:00');
Assert::same(0, $app->run(['ts', 'vacation', '2026-05-04', '2026-05-04']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('2026-05-04 09:00', $items[0]->startedAt);

// 10f. vacation range entirely on weekend errors and writes nothing
[$app, $store, , $io] = newApp('2026-05-04 12:00');
Assert::same(1, $app->run(['ts', 'vacation', '2026-05-09', '2026-05-10']));
Assert::contains('no working days', $io->getErr());
Assert::same([], $store->load());

// 10g. vacation with from > to errors
[$app, $store, , $io] = newApp('2026-05-04 12:00');
Assert::same(1, $app->run(['ts', 'vacation', '2026-05-08', '2026-05-04']));
Assert::contains('is after', $io->getErr());
Assert::same([], $store->load());

// 10h. vacation refuses atomically when ANY day in range already has a day record
//      (the existing record stays untouched and no new records are written)
[$app, $store, , $io] = newApp('2026-05-04 12:00');
$app->run(['ts', 'days', '2026-05-06']);             // existing day on Wed
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'vacation', '2026-05-04', '2026-05-08']));
Assert::contains('a full-day entry already exists on 2026-05-06', $io->getErr());
Assert::equal($snapshot, $store->load());

// 10i. vacation inserts all closed records BEFORE any open record (open-is-latest invariant)
[$app, $store, $clock] = newApp('2026-05-04 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'vacation', '2026-05-05', '2026-05-07']));
$items = $store->load();
Assert::count(4, $items);                            // 1 open + 3 vacation days
Assert::same('day', $items[0]->status);              // 2026-05-05
Assert::same('day', $items[1]->status);              // 2026-05-06
Assert::same('day', $items[2]->status);              // 2026-05-07
Assert::same('ABC-1', $items[3]->issueId);           // open record stays last
Assert::null($items[3]->endedAt);

// 10j. vacation with no date args defaults to today
//      (2026-05-04 is Monday — one record, log tagged with the custom name)
[$app, $store] = newApp('2026-05-04 12:00');
Assert::same(0, $app->run(['ts', 'vacation']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-5070', $items[0]->issueId);
Assert::same('2026-05-04 09:00', $items[0]->startedAt);
Assert::contains('(vacation)', $items[0]->log);

// 10k. `v` prefix resolves uniquely to vacation
[$app, $store] = newApp('2026-05-04 12:00');
Assert::same(0, $app->run(['ts', 'v', '2026-05-04', '2026-05-04']));
Assert::count(1, $store->load());

// 10l. vacation overrides the default issue from the CLI (type stays pre-filled)
[$app, $store] = newApp('2026-05-04 12:00');
Assert::same(0, $app->run(['ts', 'vacation', '2026-05-04', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Out of office', $items[0]->type);


// === put (closed span-long record starting at midnight; for untracked time) ===

// 10l. put writes one closed record from midnight, span long, status='new', issue+type from args
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'put', 'ABC-1', '1h 30m']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);     // default track type
Assert::same('2026-05-09 00:00', $items[0]->startedAt);
Assert::same('2026-05-09 01:30', $items[0]->endedAt);
Assert::same('new', $items[0]->status);              // NOT 'day' — regular tracking-shaped record
Assert::same(
    'created at 2026-05-09 00:00 (put) | closed at 2026-05-09 01:30 (put)',
    $items[0]->log,
);

// 10m. put with explicit type
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'put', 'ABC-1', '45m', 'doc']));
Assert::same('Documentation', $store->load()[0]->type);

// 10n. put inserts the closed record BEFORE any open record (preserves open-is-latest invariant)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'put', 'XYZ-9', '2h']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('XYZ-9', $items[0]->issueId);           // put record at index 0
Assert::same('2026-05-09 00:00', $items[0]->startedAt);
Assert::same('2026-05-09 02:00', $items[0]->endedAt);
Assert::same('ABC-1', $items[1]->issueId);           // open record stays last
Assert::null($items[1]->endedAt);

// 10o. put with bare integer expands via defaultIssuePrefix
[$app, $store, , $io] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'put', '7', '30m']));
Assert::same('SW-7', $store->load()[0]->issueId);
Assert::notContains('unusual', $io->getErr());

// 10p. put with unusual issue id is accepted with a warning
[$app, $store, , $io] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'put', 'not-id', '15m']));
Assert::same('not-id', $store->load()[0]->issueId);
Assert::contains('unusual issue id format', $io->getErr());

// 10q. multiple puts on the same day are allowed (no uniqueness check; not a day record)
[$app, $store] = newApp('2026-05-09 14:30');
$app->run(['ts', 'put', 'ABC-1', '30m']);
$app->run(['ts', 'put', 'XYZ-9', '1h']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('XYZ-9', $items[1]->issueId);

// 10r. missing args and invalid span
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'put']));
Assert::contains('missing <issue>', $io->getErr());
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'put', 'ABC-1']));
Assert::contains('missing <span>', $io->getErr());
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'put', 'ABC-1', 'garbage']));
Assert::contains("put: invalid span 'garbage'", $io->getErr());

// 10s. `put` resolves to put (no shorter unique prefix exists — `p` matches
//      pause/put/push, `pu` matches put/push)
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'put', 'ABC-1', '20m']));
Assert::count(1, $store->load());


// === status ===

// 11. status with no records prints "No tracking entries."
[$app, , , $io] = newApp();
Assert::same(0, $app->run(['ts', 'status']));
Assert::contains('No tracking entries.', $io->getOut());

// 12. status with only an open record shows it under Active
[$app, , $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->clear();
$app->run(['ts', 'status']);
Assert::contains('Active', $io->getOut());
Assert::contains('ABC-1', $io->getOut());

// 13. status with only closed records shows "No active entry." + Previous
[$app, , $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$io->clear();
$app->run(['ts', 'status']);
Assert::contains('No active entry.', $io->getOut());
Assert::contains('Previous', $io->getOut());
Assert::contains('ABC-1', $io->getOut());

// 14. status with both an open record and a prior closed record shows both
[$app, , $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-2']); // closes ABC-1, opens XYZ-2
$clock->advance('+10 minutes');
$io->clear();
$app->run(['ts', 'status']);
$out = $io->getOut();
Assert::contains('Active', $out);
Assert::contains('Previous', $out);
Assert::contains('ABC-1', $out);
Assert::contains('XYZ-2', $out);

// 15. status skips day records — only a day record present still prints "No tracking entries."
$dayOnly = new Record(
    id: 1,
    issueId: 'OOO-1',
    type: 'Out of office',
    startedAt: '2026-05-08 09:00',
    endedAt: '2026-05-08 17:00',
    log: 'created at 2026-05-08 09:00 (day) | closed at 2026-05-08 17:00 (day)',
    status: 'day',
);
[$app, , , $io] = newApp('2026-05-09 10:00', [$dayOnly]);
$app->run(['ts', 'status']);
Assert::contains('No tracking entries.', $io->getOut());
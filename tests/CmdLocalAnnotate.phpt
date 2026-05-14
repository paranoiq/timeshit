<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Local\Record;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === type (in-place type change on the open record) ===

// 1. type changes the open record's type, preserves startedAt; logs the change
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'type', 'doc']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('Documentation', $items[0]->type);
Assert::same('2026-05-09 10:00', $items[0]->startedAt);
Assert::null($items[0]->endedAt);
Assert::contains('updated type Documentation at 2026-05-09 10:30 (type)', $items[0]->log);

// 2. type with the same canonical name (different casing) is a no-op
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+10 minutes');
$snapshot = $store->load();
Assert::same(0, $app->run(['ts', 'type', 'documentation']));
Assert::equal($snapshot, $store->load());

// 3. type with no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'type', 'doc']));
Assert::contains('no open tracking entry', $io->getErr());

// 4. type with unknown name errors
[$app, $store, , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$snapshot = $store->load();
Assert::same(1, $app->run(['ts', 'type', 'nonsense']));
Assert::contains("unknown type 'nonsense'", $io->getErr());
Assert::equal($snapshot, $store->load());

// 5. type with missing argument errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'type']));
Assert::contains('missing <type>', $io->getErr());


// === switch (close current segment + open new segment with new type) ===

// 6. switch closes the open record and opens a new one with the new type
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'switch', 'doc']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);
Assert::contains('closed at 2026-05-09 10:30 (switch)', $items[0]->log);
Assert::same('Documentation', $items[1]->type);
Assert::contains('created at 2026-05-09 10:30 (switch)', $items[1]->log);
Assert::same('2026-05-09 10:30', $items[1]->startedAt);
Assert::same($items[0]->issueId, $items[1]->issueId);
Assert::null($items[1]->endedAt);

// 7. switch to the same type is a no-op (track guard short-circuits)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+30 minutes');
$snapshot = $store->load();
Assert::same(0, $app->run(['ts', 'switch', 'documentation']));
Assert::equal($snapshot, $store->load());

// 8. switch with no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'switch', 'doc']));
Assert::contains('no open tracking entry', $io->getErr());


// === note (append to last non-day record) ===

// 9. note lands on the open record when one is open
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$io = null;
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(0, $app->run(['ts', 'note', 'noticed', 'a', 'thing']));
$items = $store->load();
Assert::same('noticed a thing', $items[0]->note);
Assert::null($items[0]->endedAt);
Assert::contains('Note on', $io->getErr());
Assert::contains('ABC-1', $io->getErr());
Assert::contains('(active)', $io->getErr());

// 10. with no open record, note lands on the most recent closed
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$io->clear();
$app->run(['ts', 'note', 'note', 'after', 'end']);
$items = $store->load();
Assert::same('note after end', $items[0]->note);
Assert::contains('Note on', $io->getErr());
Assert::contains('ABC-1', $io->getErr());
Assert::contains('(last closed)', $io->getErr());

// 11. note merges into the existing note with " | "
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$app->run(['ts', 'note', 'first']);
$app->run(['ts', 'note', 'second']);
Assert::same('first | second', $store->load()[0]->note);

// 12. day records are skipped — when only a day record exists, note errors
$dayRecord = new Record(
    id: 1,
    issueId: 'OOO-1',
    type: 'Out of office',
    startedAt: '2026-05-08 09:00',
    endedAt: '2026-05-08 17:00',
    log: 'created at 2026-05-08 09:00 (day) | closed at 2026-05-08 17:00 (day)',
    status: 'day',
);
[$app, , , $io] = newApp('2026-05-09 10:00', [$dayRecord]);
Assert::same(1, $app->run(['ts', 'note', 'should', 'not', 'land']));
Assert::contains('no record to add note to', $io->getErr());

// 13. with both a day record and a regular record, note lands on the regular one
[$app, $store, $clock] = newApp('2026-05-09 10:00', [$dayRecord]);
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'note', 'on', 'regular']);
$items = $store->load();
Assert::same('', $items[0]->note); // day record untouched
Assert::same('on regular', $items[1]->note);

// 14. missing text errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'note']));
Assert::contains('missing <text>', $io->getErr());


// === type / note with explicit #id targeting ===

// 15. type #id targets a closed record (not just the open one)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'ABC-2']);
Assert::same(0, $app->run(['ts', 'type', '#1', 'doc']));
$items = $store->load();
Assert::same('Documentation', $items[0]->type);
Assert::same('Implementation', $items[1]->type);
Assert::contains('updated type Documentation at 2026-05-09 11:00 (type)', $items[0]->log);

// 16. type #id with unknown id errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'type', '#99', 'doc']));
Assert::contains('record #99 not found', $io->getErr());

// 17. type #id refuses day records
$dayRecord = new Record(
    id: 1,
    issueId: 'OOO-1',
    type: 'Out of office',
    startedAt: '2026-05-08 09:00',
    endedAt: '2026-05-08 17:00',
    log: 'created at 2026-05-08 09:00 (day) | closed at 2026-05-08 17:00 (day)',
    status: 'day',
);
[$app, , , $io] = newApp('2026-05-09 10:00', [$dayRecord]);
Assert::same(1, $app->run(['ts', 'type', '#1', 'doc']));
Assert::contains('refusing to edit day record #1', $io->getErr());

// 18. note #id appends to a closed record
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'ABC-2']);
Assert::same(0, $app->run(['ts', 'note', '#1', 'late', 'thought']));
$items = $store->load();
Assert::same('late thought', $items[0]->note);
Assert::same('', $items[1]->note);

// 19. note #id appends with " | " to existing notes
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$app->run(['ts', 'note', 'first']);
$app->run(['ts', 'note', '#1', 'second']);
Assert::same('first | second', $store->load()[0]->note);

// 20. note #id with unknown id errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'note', '#99', 'nope']));
Assert::contains('record #99 not found', $io->getErr());
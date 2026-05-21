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

// 3. type with no records errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'type', 'doc']));
Assert::contains('no entry to update', $io->getErr());

// 3a. type with no open record falls back to the last closed
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
Assert::same(0, $app->run(['ts', 'type', 'doc']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('Documentation', $items[0]->type);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);
Assert::contains('updated type Documentation at 2026-05-09 10:30 (type)', $items[0]->log);

// 3b. type skips an untracked break record and targets the real record behind it
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'pause']); // opens an untracked break record
Assert::same(0, $app->run(['ts', 'type', 'doc']));
$items = $store->load();
Assert::same('Documentation', $items[0]->type); // real record changed
Assert::same('', $items[1]->type);              // untracked break untouched
Assert::same('untracked', $items[1]->status);

// 3c. type with only an untracked record errors (no eligible target)
$break = new Record(
    id: 1,
    issueId: '',
    type: '',
    startedAt: '2026-05-09 10:00',
    endedAt: null,
    log: 'created at 2026-05-09 10:00 (pause)',
    status: 'untracked',
);
[$app, , , $io] = newApp('2026-05-09 10:00', [$break]);
Assert::same(1, $app->run(['ts', 'type', 'doc']));
Assert::contains('no entry to update', $io->getErr());

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
Assert::contains('no entry to add note to', $io->getErr());

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
Assert::contains('entry #99 not found', $io->getErr());

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
Assert::contains('refusing to edit day entry #1', $io->getErr());

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
Assert::contains('entry #99 not found', $io->getErr());


// === fix (change the issue id of the last non-day record) ===

// 21. fix on an open record swaps issueId; preserves type/startedAt; logs the edit
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
Assert::same(0, $app->run(['ts', 'fix', 'XYZ-2']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('XYZ-2', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 10:00', $items[0]->startedAt);
Assert::null($items[0]->endedAt);
Assert::contains('edited issueId from ABC-1 to XYZ-2 at 2026-05-09 10:15 (fix)', $items[0]->log);

// 22. fix lowercases & expands a bare numeric id using defaultIssuePrefix
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
Assert::same(0, $app->run(['ts', 'fix', '42']));
Assert::same('SW-42', $store->load()[0]->issueId);

// 23. fix with the same id is a no-op
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$snapshot = $store->load();
Assert::same(0, $app->run(['ts', 'fix', 'ABC-1']));
Assert::equal($snapshot, $store->load());

// 24. fix with no open record falls back to the last closed
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
Assert::same(0, $app->run(['ts', 'fix', 'XYZ-2']));
$items = $store->load();
Assert::same('XYZ-2', $items[0]->issueId);
Assert::contains('edited issueId from ABC-1 to XYZ-2', $items[0]->log);

// 25. fix with no records errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'fix', 'ABC-1']));
Assert::contains('no entry to fix', $io->getErr());

// 26. fix missing issue arg errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'fix']));
Assert::contains('missing <issue>', $io->getErr());

// 27. fix refuses to assign an issue to an untracked break record
[$app, $store, $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'pause']); // creates an open untracked break record
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'fix', 'XYZ-2']));
Assert::contains('untracked', $io->getErr());
Assert::equal($snapshot, $store->load());

// 28. fix #id targets a closed earlier record
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'ABC-2']);
Assert::same(0, $app->run(['ts', 'fix', '#1', 'XYZ-9']));
$items = $store->load();
Assert::same('XYZ-9', $items[0]->issueId);
Assert::same('ABC-2', $items[1]->issueId);
Assert::contains('edited issueId from ABC-1 to XYZ-9', $items[0]->log);

// 29. fix #id with unknown id errors
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'fix', '#99', 'XYZ-2']));
Assert::contains('entry #99 not found', $io->getErr());

// 30. fix #id refuses day records
$dayRecordForFix = new Record(
    id: 1,
    issueId: 'OOO-1',
    type: 'Out of office',
    startedAt: '2026-05-08 09:00',
    endedAt: '2026-05-08 17:00',
    log: 'created at 2026-05-08 09:00 (day) | closed at 2026-05-08 17:00 (day)',
    status: 'day',
);
[$app, , , $io] = newApp('2026-05-09 10:00', [$dayRecordForFix]);
Assert::same(1, $app->run(['ts', 'fix', '#1', 'XYZ-2']));
Assert::contains('refusing to edit day entry #1', $io->getErr());
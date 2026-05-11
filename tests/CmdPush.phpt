<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Local\Record;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === push (sums closed records by (day, issue, type) and POSTs them to YouTrack) ===

// 1. push with no records prints "Nothing to push"
[$app, , , $io] = newApp('2026-05-11 10:00');
Assert::same(0, $app->run(['ts', 'push']));
Assert::contains('Nothing to push (cutoff: 2026-05-10)', $io->getErr());

// 2. push pushes one closed record from yesterday and archives it
$rec = new Record(
    id: 1,
    issueId: 'SW-100',
    type: 'Implementation',
    startedAt: '2026-05-10 09:00',
    endedAt: '2026-05-10 11:00',
    log: 'created at 2026-05-10 09:00 (track) | closed at 2026-05-10 11:00 (end)',
);
[$app, $store, , $io, $pusher] = newApp('2026-05-11 10:00', [$rec]);
Assert::same(0, $app->run(['ts', 'push']));
Assert::count(1, $pusher->calls);
Assert::same('SW-100', $pusher->calls[0]['issueId']);
Assert::same(120, $pusher->calls[0]['minutes']);
// Type id '0' from the stub (Implementation is the first type in bootstrap)
Assert::same('0', $pusher->calls[0]['typeId']);
// records.neon is now empty; archive has the synced record
Assert::same([], $store->load());
$archive = $store->loadArchive();
Assert::count(1, $archive);
Assert::same('synced', $archive[0]->status);
Assert::same('wi-1', $archive[0]->workItemId);
Assert::contains('synced as wi-1 at 2026-05-11 10:00 (push)', $archive[0]->log);
Assert::contains('SW-100', $io->getErr());
Assert::contains('wi-1', $io->getErr());

// 3. push sums multiple records on the same (date, issue, type) into one work item
$r1 = new Record(id: 1, issueId: 'SW-200', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: '');
$r2 = new Record(id: 2, issueId: 'SW-200', type: 'Implementation', startedAt: '2026-05-10 11:00', endedAt: '2026-05-10 12:30', log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$r1, $r2]);
$app->run(['ts', 'push']);
Assert::count(1, $pusher->calls);
Assert::same(150, $pusher->calls[0]['minutes']);   // 60 + 90
Assert::same([], $store->load());
Assert::count(2, $store->loadArchive());

// 4. different types on the same issue+date create separate work items
$r1 = new Record(id: 1, issueId: 'SW-300', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: '');
$r2 = new Record(id: 2, issueId: 'SW-300', type: 'Documentation', startedAt: '2026-05-10 10:00', endedAt: '2026-05-10 10:30', log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$r1, $r2]);
$app->run(['ts', 'push']);
Assert::count(2, $pusher->calls);
// Sorted by group key (date|issueId|type) — Documentation < Implementation alphabetically
Assert::same('2', $pusher->calls[0]['typeId']);
Assert::same(30, $pusher->calls[0]['minutes']);
Assert::same('0', $pusher->calls[1]['typeId']);
Assert::same(60, $pusher->calls[1]['minutes']);
Assert::same([], $store->load());

// 5. today's records are NOT pushed by default (cutoff = yesterday)
$today = new Record(id: 1, issueId: 'SW-400', type: 'Implementation', startedAt: '2026-05-11 08:00', endedAt: '2026-05-11 09:00', log: '');
$yest = new Record(id: 2, issueId: 'SW-401', type: 'Implementation', startedAt: '2026-05-10 08:00', endedAt: '2026-05-10 09:00', log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$today, $yest]);
$app->run(['ts', 'push']);
Assert::count(1, $pusher->calls);
Assert::same('SW-401', $pusher->calls[0]['issueId']);
// today's record remains in records.neon
$remaining = $store->load();
Assert::count(1, $remaining);
Assert::same('SW-400', $remaining[0]->issueId);

// 6. `push today` includes today's closed records
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$today, $yest]);
$app->run(['ts', 'push', 'today']);
Assert::count(2, $pusher->calls);
Assert::same([], $store->load());

// 7. push with explicit date cutoff
$r = new Record(id: 1, issueId: 'SW-500', type: 'Implementation', startedAt: '2026-05-08 09:00', endedAt: '2026-05-08 11:00', log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$r]);
$app->run(['ts', 'push', '2026-05-09']);
Assert::count(1, $pusher->calls);
Assert::same([], $store->load());

// 8. open records are never pushed; they stay in records.neon
$open = new Record(id: 1, issueId: 'SW-600', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: null, log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$open]);
$app->run(['ts', 'push']);
Assert::same([], $pusher->calls);
Assert::count(1, $store->load());

// 9. records with status='untracked' (pause breaks) are skipped
$break = new Record(id: 1, issueId: '', type: '', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 09:30', log: '', status: 'untracked');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$break]);
$app->run(['ts', 'push']);
Assert::same([], $pusher->calls);
Assert::count(1, $store->load());

// 10. records with status='synced' are skipped (defensive)
$synced = new Record(id: 1, issueId: 'SW-700', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: '', status: 'synced', workItemId: 'wi-old');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$synced]);
$app->run(['ts', 'push']);
Assert::same([], $pusher->calls);
Assert::count(1, $store->load());

// 11. on push failure, the record stays in records.neon, gets status='failed' + log entry
$rec = new Record(id: 1, issueId: 'SW-NOPE', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: 'x');
[$app, $store, , $io, $pusher] = newApp('2026-05-11 10:00', [$rec]);
$pusher->setResults([new RuntimeException('issue SW-NOPE not found')]);
Assert::same(0, $app->run(['ts', 'push']));
Assert::count(1, $pusher->calls);
Assert::same([], $store->loadArchive());
$items = $store->load();
Assert::count(1, $items);
Assert::same('failed', $items[0]->status);
Assert::contains("sync failed (issue SW-NOPE not found) at 2026-05-11 10:00 (push)", $items[0]->log);
Assert::contains('SW-NOPE', $io->getErr());

// 12. failed records are retried on subsequent push and can succeed
$failed = new Record(id: 1, issueId: 'SW-800', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: 'created at ... | sync failed (...) at ... (push)', status: 'failed');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$failed]);
$app->run(['ts', 'push']);
Assert::count(1, $pusher->calls);
Assert::same([], $store->load());
Assert::count(1, $store->loadArchive());
Assert::same('synced', $store->loadArchive()[0]->status);

// 13. notes from records in the same group are joined into the work-item text
$r1 = new Record(id: 1, issueId: 'SW-900', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: '', note: 'first note');
$r2 = new Record(id: 2, issueId: 'SW-900', type: 'Implementation', startedAt: '2026-05-10 11:00', endedAt: '2026-05-10 12:00', log: '', note: 'second note');
[$app, , , , $pusher] = newApp('2026-05-11 10:00', [$r1, $r2]);
$app->run(['ts', 'push']);
Assert::same('first note | second note', $pusher->calls[0]['text']);

// 14. duplicate notes are not repeated
$r1 = new Record(id: 1, issueId: 'SW-910', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: '', note: 'same');
$r2 = new Record(id: 2, issueId: 'SW-910', type: 'Implementation', startedAt: '2026-05-10 11:00', endedAt: '2026-05-10 12:00', log: '', note: 'same');
[$app, , , , $pusher] = newApp('2026-05-11 10:00', [$r1, $r2]);
$app->run(['ts', 'push']);
Assert::same('same', $pusher->calls[0]['text']);

// 15. records spanning multiple days are grouped by their startedAt date
$r1 = new Record(id: 1, issueId: 'SW-1000', type: 'Implementation', startedAt: '2026-05-09 09:00', endedAt: '2026-05-09 10:00', log: '');
$r2 = new Record(id: 2, issueId: 'SW-1000', type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 11:00', log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$r1, $r2]);
$app->run(['ts', 'push']);
Assert::count(2, $pusher->calls);
// First call is for the earlier date (sorted ascending)
Assert::same(60, $pusher->calls[0]['minutes']);    // 2026-05-09
Assert::same(120, $pusher->calls[1]['minutes']);   // 2026-05-10

// 16. day-status records ARE pushed (they have issueId + type and are closed)
$day = new Record(
    id: 1,
    issueId: 'SW-5070',
    type: 'Out of office',
    startedAt: '2026-05-10 09:00',
    endedAt: '2026-05-10 17:00',
    log: 'created at 2026-05-10 09:00 (day) | closed at 2026-05-10 17:00 (day)',
    status: 'day',
);
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$day]);
$app->run(['ts', 'push']);
Assert::count(1, $pusher->calls);
Assert::same(480, $pusher->calls[0]['minutes']);  // 8h
Assert::same('SW-5070', $pusher->calls[0]['issueId']);
Assert::same([], $store->load());

// 17. mixed success and failure: succeeded records archived, failed records marked
$good = new Record(id: 1, issueId: 'SW-OK',  type: 'Implementation', startedAt: '2026-05-10 09:00', endedAt: '2026-05-10 10:00', log: '');
$bad  = new Record(id: 2, issueId: 'SW-BAD', type: 'Implementation', startedAt: '2026-05-10 11:00', endedAt: '2026-05-10 12:00', log: '');
[$app, $store, , , $pusher] = newApp('2026-05-11 10:00', [$good, $bad]);
// Groups are sorted by key — SW-BAD comes before SW-OK alphabetically (after the date prefix)
$pusher->setResults([new RuntimeException('boom'), 'wi-42']);
$app->run(['ts', 'push']);
Assert::count(2, $pusher->calls);
$remaining = $store->load();
Assert::count(1, $remaining);
Assert::same('SW-BAD', $remaining[0]->issueId);
Assert::same('failed', $remaining[0]->status);
$archived = $store->loadArchive();
Assert::count(1, $archived);
Assert::same('SW-OK', $archived[0]->issueId);
Assert::same('wi-42', $archived[0]->workItemId);
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


// === day (full 8h record at 09:00–17:00 on a chosen date) ===

// 6. day appends a closed 8h record with status='day' and a (day)-tagged log
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'day', 'OOO-1', '2026-05-08']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('OOO-1', $items[0]->issueId);
Assert::same('Out of office', $items[0]->type);
Assert::same('2026-05-08 09:00', $items[0]->startedAt);
Assert::same('2026-05-08 17:00', $items[0]->endedAt);
Assert::same('day', $items[0]->status);
Assert::same(
    'created at 2026-05-08 09:00 (day) | closed at 2026-05-08 17:00 (day)',
    $items[0]->log,
);

// 7. day with explicit type
[$app, $store] = newApp('2026-05-09 14:30');
$app->run(['ts', 'day', 'ABC-1', '2026-05-08', 'doc']);
Assert::same('Documentation', $store->load()[0]->type);

// 8. day refuses a second full-day on the same calendar date
[$app, $store, , $io] = newApp('2026-05-09 14:30');
$app->run(['ts', 'day', 'OOO-1', '2026-05-08']);
$snapshot = $store->load();
$io->clear();
Assert::same(1, $app->run(['ts', 'day', 'XYZ-2', '2026-05-08']));
Assert::contains('a full-day record already exists on 2026-05-08', $io->getErr());
Assert::equal($snapshot, $store->load());

// 9. day inserts the closed record BEFORE any open record (preserves the open-is-latest invariant)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'day', 'OOO-1', '2026-05-08']);
$items = $store->load();
Assert::count(2, $items);
// day record is at index 0, open record stays at index 1 (latest)
Assert::same('OOO-1', $items[0]->issueId);
Assert::same('day', $items[0]->status);
Assert::same('ABC-1', $items[1]->issueId);
Assert::null($items[1]->endedAt);

// 10. day with unusual issue id is accepted with a warning
[$app, $store, , $io] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'day', 'not-id', '2026-05-08']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('not-id', $items[0]->issueId);
Assert::contains('unusual issue id format', $io->getErr());

// 10b. day with a plain integer expands it with the default prefix
[$app, $store, , $io] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'day', '7', '2026-05-08']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-7', $items[0]->issueId);
Assert::notContains('unusual', $io->getErr());


// === status ===

// 11. status with no records prints "No tracking records."
[$app, , , $io] = newApp();
Assert::same(0, $app->run(['ts', 'status']));
Assert::contains('No tracking records.', $io->getOut());

// 12. status with only an open record shows it under Active
[$app, , $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$io->clear();
$app->run(['ts', 'status']);
Assert::contains('Active', $io->getOut());
Assert::contains('ABC-1', $io->getOut());

// 13. status with only closed records shows "No active record." + Previous
[$app, , $clock, $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'end']);
$io->clear();
$app->run(['ts', 'status']);
Assert::contains('No active record.', $io->getOut());
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

// 15. status skips day records — only a day record present still prints "No tracking records."
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
Assert::contains('No tracking records.', $io->getOut());
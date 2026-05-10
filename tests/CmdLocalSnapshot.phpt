<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;
use Timeshit\Local\Record;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// === checkout (called by hooks/post-checkout — like track but with branch + repo) ===

// 1. checkout extracts the issue id from the branch and stores branch + repo
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'checkout', 'ABC-123-add-widget', 'my-repo']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-123', $items[0]->issueId);
Assert::same('ABC-123-add-widget', $items[0]->branch);
Assert::same('my-repo', $items[0]->repo);
Assert::same('Implementation', $items[0]->type);
Assert::same('checkout', $items[0]->startTrigger);
Assert::null($items[0]->endedAt);

// 2. branches without an issue prefix become the issue id verbatim
[$app, $store] = newApp();
$app->run(['ts', 'checkout', 'main', 'my-repo']);
Assert::same('main', $store->load()[0]->issueId);
Assert::same('main', $store->load()[0]->branch);

// 3. checkout to the same branch+repo+issue is a no-op
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
Assert::same('checkout', $items[0]->endTrigger);
Assert::same('XYZ-2', $items[1]->issueId);

// 5. missing branch / repo are rejected
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'checkout']));
Assert::contains('missing <branch>', $io->getErr());
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'checkout', 'ABC-1']));
Assert::contains('missing <repo>', $io->getErr());


// === day (full 8h record at 09:00–17:00 on a chosen date) ===

// 6. day appends a closed 8h record with start/end triggers `day`
[$app, $store] = newApp('2026-05-09 14:30');
Assert::same(0, $app->run(['ts', 'day', 'OOO-1', '2026-05-08']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('OOO-1', $items[0]->issueId);
Assert::same('Out of office', $items[0]->type);
Assert::same('2026-05-08 09:00', $items[0]->startedAt);
Assert::same('2026-05-08 17:00', $items[0]->endedAt);
Assert::same('day', $items[0]->startTrigger);
Assert::same('day', $items[0]->endTrigger);
Assert::same('2026-05-09 14:30', $items[0]->createdAt); // when the user ran the command
Assert::null($items[0]->branch);
Assert::same('', $items[0]->repo);

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
Assert::same('day', $items[0]->startTrigger);
Assert::same('ABC-1', $items[1]->issueId);
Assert::null($items[1]->endedAt);

// 10. day with invalid issue is rejected
[$app, $store, , $io] = newApp('2026-05-09 14:30');
Assert::same(1, $app->run(['ts', 'day', 'not-id', '2026-05-08']));
Assert::contains("invalid issue 'not-id'", $io->getErr());
Assert::same([], $store->load());


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
    branch: null,
    repo: '',
    type: 'Out of office',
    startedAt: '2026-05-08 09:00',
    startTrigger: 'day',
    endedAt: '2026-05-08 17:00',
    endTrigger: 'day',
    createdAt: '2026-05-08 14:00',
    modifiedAt: '2026-05-08 14:00',
);
[$app, , , $io] = newApp('2026-05-09 10:00', [$dayOnly]);
$app->run(['ts', 'status']);
Assert::contains('No tracking records.', $io->getOut());
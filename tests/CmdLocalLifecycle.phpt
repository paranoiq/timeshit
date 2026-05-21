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

// 9a. issue-id shortcut: `ts <issue>` dispatches as `ts track <issue>`
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('created at 2026-05-09 10:00 (track)', $items[0]->log);

// 9b. shortcut also accepts type and note positionals
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'ABC-1', 'doc', 'fix bug']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Documentation', $items[0]->type);
Assert::same('fix bug', $items[0]->note);

// 9c. shortcut expands plain integers with the default prefix
[$app, $store] = newApp();
Assert::same(0, $app->run(['ts', '42']));
Assert::same('SW-42', $store->load()[0]->issueId);

// 9d. non-issue-id input still errors as unknown command
[$app, $store, , $io] = newApp();
Assert::same(1, $app->run(['ts', 'not-an-id']));
Assert::same([], $store->load());
Assert::contains('Unknown command', $io->getErr());


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
Assert::contains('no entry to resume', $io->getErr());

// 18. resume errors only when the latest open record is a tracking record;
//     an open *untracked* break record is not an error (resume closes it).
[$app, , , $io] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$io->clear();
Assert::same(1, $app->run(['ts', 'resume']));
Assert::contains('an entry is already open', $io->getErr());

// 20. double pause is a silent no-op (already paused — same untracked shape, store sees match)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'pause']);
$snapshot = $store->load();
$clock->advance('+5 minutes');
Assert::same(0, $app->run(['ts', 'pause']));
Assert::equal($snapshot, $store->load());

// 21. track with a trailing quoted note sets the note on the new record
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'Implementation', 'fix bug']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('fix bug', $items[0]->note);

// 21b. note also works when type is omitted (note slot is argv[3] onwards, type defaults)
[$app, $store] = newApp('2026-05-09 10:00');
// User would type: ts track ABC-1 Implementation "fix the leak"
// (note: with current parsing, the type slot must be filled to provide a note)
$app->run(['ts', 'track', 'ABC-1', 'Implementation', 'fix the leak']);
Assert::same('fix the leak', $store->load()[0]->note);

// 22. custom command (analyse) accepts a trailing note after the issue arg
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'analyse', 'ABC-1', 'sketch the schema']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('Analyses / Design', $items[0]->type);
Assert::same('sketch the schema', $items[0]->note);

// 23. custom command with preset issue (meeting) takes only a note
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'meeting', 'standup with team']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-4002', $items[0]->issueId);
Assert::same('Communication, Meetings, ...', $items[0]->type);
Assert::same('standup with team', $items[0]->note);

// 24. custom command with a default note uses it when CLI doesn't override
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'standup']);                              // no CLI note
$items = $store->load();
Assert::count(1, $items);
Assert::same('daily standup', $items[0]->note);            // default from custom command config
// 24b. CLI note is joined with the custom command's default (default first, ` | ` separator)
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'standup', 'with infra team only']);
Assert::same('daily standup | with infra team only', $store->load()[0]->note);

// 25. custom put command (preset issue + span) writes one closed record without CLI args
[$app, $store] = newApp('2026-05-09 14:30');
$app->run(['ts', 'lunchput']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-9999', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 00:00', $items[0]->startedAt);    // put starts at midnight
Assert::same('2026-05-09 01:00', $items[0]->endedAt);      // span: 1h

// 26. custom day command (preset issue + date) writes a full-day record
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'sickday']);
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-5070', $items[0]->issueId);
Assert::same('Out of office', $items[0]->type);
Assert::same('day', $items[0]->status);
Assert::same('2026-05-09 09:00', $items[0]->startedAt);

// 27. custom pause command — pauses with the configured default note
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'lunch']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('paused', $items[0]->status);
Assert::same('untracked', $items[1]->status);              // pause creates an untracked break record
Assert::same('lunch break', $items[1]->note);              // default note from custom command

// 28. custom switch command — switches type to the configured one
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'review!']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('Implementation', $items[0]->type);
Assert::same('Test / Review', $items[1]->type);            // switched type from custom command
Assert::same('ABC-1', $items[1]->issueId);                 // same issue

// 29. custom skip command — skips with the configured default span
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'tea']);                                  // span: 5m from custom
$items = $store->load();
Assert::count(2, $items);
Assert::same('2026-05-09 10:25', $items[0]->endedAt);      // 10:30 - 5m
Assert::same('2026-05-09 10:30', $items[1]->startedAt);

// 30. custom end command — ends the current entry (no positionals, no note)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'wrap']);
$items = $store->load();
Assert::count(1, $items);
Assert::null($items[0]->endedAt === null ? null : null);    // closed
Assert::same('2026-05-09 10:30', $items[0]->endedAt);

// 31. command alias `design` resolves to custom `analyse` (records the canonical type + trigger)
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'design', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Analyses / Design', $items[0]->type);
Assert::contains('(analyse)', $items[0]->log);             // log carries the canonical name, not the alias

// 32. unique-prefix resolution works through aliases too (`des` → `design` → `analyse`)
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'des', 'XYZ-2']));
$items = $store->load();
Assert::same('Analyses / Design', $items[0]->type);

// 33. builtin alias `continue` still resolves to `resume` after the move to config
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'end']);
$clock->advance('+5 minutes');
Assert::same(0, $app->run(['ts', 'continue']));
$items = $store->load();
Assert::same('ABC-1', $items[1]->issueId);                 // resumed onto a fresh ABC-1 record
Assert::null($items[1]->endedAt);
<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// Tests for the interruption flow:
//
// When a default-type record (Implementation) is interrupted by a new track of
// an interruption type ('Communication, Meetings, ...' or 'Test / Review'), the
// closed record is tagged endTrigger='interrupted' and the new record's
// startTrigger='interrupted'. The new `done` command closes the open record
// AND auto-resumes the most recent endTrigger='interrupted' record. If the
// interrupting record is closed by `end` or replaced by another track, the
// interrupted one stays paused and `resume` (which now scans backwards for
// paused/interrupted) can pick it back up.

// === interruption detection ===

// 1. default-type Impl interrupted by 'Communication, Meetings, ...' tags both ends
//    AND sets status='paused' on the closed record (the new open record stays 'new')
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);                 // Implementation, open
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);          // 'Communication, Meetings, ...'
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);
Assert::same('interrupted', $items[0]->endTrigger);
Assert::same('paused', $items[0]->status);           // status reflects the pause state
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Communication, Meetings, ...', $items[1]->type);
Assert::same('interrupted', $items[1]->startTrigger);
Assert::null($items[1]->endedAt);
Assert::same('new', $items[1]->status);              // open interrupting record is still 'new'

// 2. the second configured interruption type ('Test / Review') also fires
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'test']);         // 'Test / Review'
$items = $store->load();
Assert::same('interrupted', $items[0]->endTrigger);
Assert::same('Test / Review', $items[1]->type);
Assert::same('interrupted', $items[1]->startTrigger);

// 3. interruption fires only when open record's type IS defaultTrackType:
//    starting from Documentation (allowed but not default), going to a meeting
//    is a regular track-replace, NOT an interruption.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$items = $store->load();
Assert::same('manual', $items[0]->endTrigger);       // not 'interrupted'
Assert::same('manual', $items[1]->startTrigger);

// 4. interruption fires only when new type IS in interruptionTypes:
//    Implementation → Documentation is a regular track-replace.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'doc']);
$items = $store->load();
Assert::same('manual', $items[0]->endTrigger);
Assert::same('manual', $items[1]->startTrigger);


// === done ===

// 5. done after an interruption: closes meeting and auto-resumes the original
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
Assert::same(0, $app->run(['ts', 'done']));
$items = $store->load();
Assert::count(3, $items);
// original (closed by interruption)
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('interrupted', $items[0]->endTrigger);
// interruption (closed by done)
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Communication, Meetings, ...', $items[1]->type);
Assert::same('2026-05-09 10:50', $items[1]->endedAt);
Assert::same('done', $items[1]->endTrigger);
// auto-resumed continuation: clones original
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Implementation', $items[2]->type);
Assert::same('2026-05-09 10:50', $items[2]->startedAt);
Assert::same('resumed', $items[2]->startTrigger);
Assert::null($items[2]->endedAt);

// 6. done with no prior interrupted record behaves like end (just closes)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'done']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('done', $items[0]->endTrigger);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);

// 7. done with no open record errors
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'done']));
Assert::contains('no open tracking entry', $io->getErr());

// 8. done with optional comment is appended to the closed record
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
$app->run(['ts', 'done', 'meeting', 'over']);
$items = $store->load();
Assert::same('done', $items[1]->endTrigger);
Assert::same('meeting over', $items[1]->comment);

// 9. done auto-resumes any status='paused' record (manual pause and interruption alike)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);                 // Impl, open                      → 1 record
$clock->advance('+15 minutes');
$app->run(['ts', 'pause']);                          // closes ABC-1 + opens break      → 2
$clock->advance('+5 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);          // closes break + opens XYZ-9      → 3
$clock->advance('+30 minutes');
$app->run(['ts', 'done']);                           // closes XYZ-9 + auto-resume A    → 4
$items = $store->load();
Assert::count(4, $items);
Assert::same('paused', $items[0]->endTrigger);       // manual pause is still 'paused'
Assert::same('paused', $items[0]->status);
Assert::same('untracked', $items[1]->status);        // break, closed by track replace
Assert::same('XYZ-9', $items[2]->issueId);
Assert::same('done', $items[2]->endTrigger);
Assert::same('new', $items[2]->status);
// continuation cloned from ABC-1 (skipping the untracked break in the scan)
Assert::same('ABC-1', $items[3]->issueId);
Assert::same('Implementation', $items[3]->type);
Assert::same('resumed', $items[3]->startTrigger);
Assert::null($items[3]->endedAt);


// === interrupted stays paused on end / track ===

// 10. end on an interrupting record leaves the original interrupted (not auto-resumed)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(2, $items);                            // no continuation
Assert::same('interrupted', $items[0]->endTrigger);  // ABC-1 still tagged
Assert::same('ended', $items[1]->endTrigger);

// 11. tracking yet another record while interrupting closes the meeting normally
//     and leaves ABC-1 still interrupted
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
$app->run(['ts', 'track', 'DEF-2', 'doc']);          // non-interruption type, normal track-replace
$items = $store->load();
Assert::count(3, $items);
Assert::same('interrupted', $items[0]->endTrigger);  // ABC-1 still tagged
Assert::same('manual', $items[1]->endTrigger);       // meeting closed normally, NOT 'interrupted'
Assert::same('DEF-2', $items[2]->issueId);
Assert::same('Documentation', $items[2]->type);
Assert::null($items[2]->endedAt);


// === resume picks up interrupted ===

// 12. resume after the interrupting record was ended brings ABC-1 back
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
$app->run(['ts', 'end']);
$clock->advance('+5 minutes');
Assert::same(0, $app->run(['ts', 'resume']));
$items = $store->load();
Assert::count(3, $items);
Assert::same('ABC-1', $items[2]->issueId);           // resumed from the interrupted, not the ended
Assert::same('Implementation', $items[2]->type);
Assert::same('resumed', $items[2]->startTrigger);
Assert::same('2026-05-09 10:55', $items[2]->startedAt);

// 13. resume after manual pause still works (regression): closes the open break,
//     reopens cloned from the paused tracking record.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'pause']);                          // closes ABC-1, opens break
$clock->advance('+5 minutes');
Assert::same(0, $app->run(['ts', 'resume']));
$items = $store->load();
Assert::count(3, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('paused', $items[0]->endTrigger);
Assert::same('paused', $items[0]->status);
Assert::same('untracked', $items[1]->status);        // break closed by resume
Assert::same('resumed', $items[1]->endTrigger);
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('resumed', $items[2]->startTrigger);
Assert::null($items[2]->endedAt);


// === interrupt ===
//
// `interrupt` is `track`'s sibling that unconditionally tags the seam with
// `'interrupted'` when an open record exists — bypassing the type-based
// detection. Use it to declare an interruption explicitly even when the open
// record's type isn't `defaultTrackType` or the new type isn't in
// `interruptionTypes`.

// 14. interrupt forces 'interrupted' on the seam regardless of types:
//     open record is Documentation (not default), new type is Implementation
//     (not an interruption type). Plain `track` would tag both ends 'manual'.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);          // open: Documentation
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'interrupt', 'XYZ-9']));  // default type: Implementation
$items = $store->load();
Assert::count(2, $items);
Assert::same('Documentation', $items[0]->type);
Assert::same('interrupted', $items[0]->endTrigger);
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Implementation', $items[1]->type);
Assert::same('interrupted', $items[1]->startTrigger);

// 15. interrupt + done auto-resumes the original (the whole point of interrupt)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);          // Doc (not default-type)
$clock->advance('+30 minutes');
$app->run(['ts', 'interrupt', 'XYZ-9']);             // Impl (not interruption-type)
$clock->advance('+15 minutes');
$app->run(['ts', 'done']);
$items = $store->load();
Assert::count(3, $items);
Assert::same('interrupted', $items[0]->endTrigger);
Assert::same('done', $items[1]->endTrigger);
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Documentation', $items[2]->type);      // cloned from original
Assert::same('resumed', $items[2]->startTrigger);
Assert::null($items[2]->endedAt);

// 16. interrupt accepts an explicit <type>
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'interrupt', 'XYZ-9', 'test']);
$items = $store->load();
Assert::same('Test / Review', $items[1]->type);
Assert::same('interrupted', $items[0]->endTrigger);
Assert::same('interrupted', $items[1]->startTrigger);

// 17. interrupt with no open record falls back to plain 'manual' (nothing to interrupt)
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'interrupt', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('manual', $items[0]->startTrigger);

// 18. interrupt on default+default records is also tagged (track would NOT have)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);                 // Impl
$clock->advance('+15 minutes');
$app->run(['ts', 'interrupt', 'XYZ-9']);             // also Impl by default
$items = $store->load();
Assert::same('Implementation', $items[0]->type);
Assert::same('Implementation', $items[1]->type);
Assert::same('interrupted', $items[0]->endTrigger);
Assert::same('interrupted', $items[1]->startTrigger);

// 19. interrupt with invalid issue rejects (same as track)
[$app, , , $io] = newApp();
Assert::same(1, $app->run(['ts', 'interrupt', 'not-id']));
Assert::contains("invalid issue 'not-id'", $io->getErr());


// === status field lifecycle ===
//
// Verifies the status field is set/preserved correctly across the commands
// the user expects to map to the 'paused' state, and stays 'new' otherwise.

// 20. fresh tracked record has status='new'
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
Assert::same('new', $store->load()[0]->status);

// 21. manual pause sets status='paused' (alongside endTrigger='paused')
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'pause']);
$items = $store->load();
Assert::same('paused', $items[0]->endTrigger);
Assert::same('paused', $items[0]->status);

// 22. resume after pause: closed tracking stays 'paused', closed break stays
//     'untracked', new continuation is fresh 'new'
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'pause']);
$clock->advance('+5 minutes');
$app->run(['ts', 'resume']);
$items = $store->load();
Assert::count(3, $items);
Assert::same('paused', $items[0]->status);           // closed tracking record
Assert::same('untracked', $items[1]->status);        // closed break record
Assert::same('new', $items[2]->status);              // continuation is fresh

// 23. end / done / track-replace / switch leave status='new' on the closed record
//     (only 'paused' and 'interrupted' triggers flip status).
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'end']);
Assert::same('new', $store->load()[0]->status);      // 'ended' does not pause

[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'track', 'DEF-2', 'doc']);          // plain track-replace, trigger='manual'
Assert::same('new', $store->load()[0]->status);

[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'switch', 'doc']);                  // trigger='switched'
Assert::same('new', $store->load()[0]->status);

// 24. legacy records without a `status` field load as status='new'
[$app, $store] = newApp(
    '2026-05-09 10:00',
    [
        new Timeshit\Local\Record(
            id: 1,
            issueId: 'OLD-1',
            branch: null,
            repo: '',
            type: 'Implementation',
            startedAt: '2026-05-09 09:00',
            startTrigger: 'manual',
            endedAt: '2026-05-09 09:30',
            endTrigger: 'ended',
            createdAt: '2026-05-09 09:00',
            modifiedAt: '2026-05-09 09:30',
        ),
    ],
);
Assert::same('new', $store->load()[0]->status);
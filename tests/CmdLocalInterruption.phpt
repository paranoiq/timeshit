<?php declare(strict_types=1);

use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

Environment::setup();

// Tests for the interruption flow:
//
// When a default-type record (Implementation) is interrupted by a new track of
// an interruption type ('Communication, Meetings, ...' or 'Test / Review'), the
// store flips the closed record's status to 'paused'. `done` closes the open
// record AND auto-resumes the most recent status='paused' record. If the
// interrupting record is closed by `end` or replaced by another track, the
// interrupted one stays paused and `resume` (which scans for status='paused')
// can pick it back up.

// === interruption detection ===

// 1. default-type Impl interrupted by 'Communication, Meetings, ...' flips the
//    closed record's status to 'paused' (the new open record stays 'new')
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);                 // Implementation, open
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);          // 'Communication, Meetings, ...'
$items = $store->load();
Assert::count(2, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('Implementation', $items[0]->type);
Assert::same('2026-05-09 10:30', $items[0]->endedAt);
Assert::same('paused', $items[0]->status);           // status reflects the pause state
Assert::contains('closed at 2026-05-09 10:30 (track)', $items[0]->log);
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Communication, Meetings, ...', $items[1]->type);
Assert::contains('created at 2026-05-09 10:30 (track)', $items[1]->log);
Assert::null($items[1]->endedAt);
Assert::same('new', $items[1]->status);              // open interrupting record is still 'new'

// 2. the second configured interruption type ('Test / Review') also fires
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'test']);         // 'Test / Review'
$items = $store->load();
Assert::same('paused', $items[0]->status);
Assert::same('Test / Review', $items[1]->type);

// 3. interruption fires only when open record's type IS defaultTrackType:
//    starting from Documentation (allowed but not default), going to a meeting
//    is a regular track-replace, NOT an interruption.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$items = $store->load();
Assert::same('new', $items[0]->status);              // not 'paused'

// 4. interruption fires only when new type IS in interruptionTypes:
//    Implementation → Documentation is a regular track-replace.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'doc']);
$items = $store->load();
Assert::same('new', $items[0]->status);


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
Assert::same('paused', $items[0]->status);
// interruption (closed by done)
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Communication, Meetings, ...', $items[1]->type);
Assert::same('2026-05-09 10:50', $items[1]->endedAt);
Assert::contains('closed at 2026-05-09 10:50 (done)', $items[1]->log);
// auto-resumed continuation: clones original
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Implementation', $items[2]->type);
Assert::same('2026-05-09 10:50', $items[2]->startedAt);
Assert::contains('created at 2026-05-09 10:50 (done)', $items[2]->log);
Assert::null($items[2]->endedAt);

// 6. done with no prior interrupted record behaves like end (just closes)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'done']));
$items = $store->load();
Assert::count(1, $items);
Assert::contains('closed at 2026-05-09 10:30 (done)', $items[0]->log);
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
Assert::contains('closed at 2026-05-09 10:50 (done)', $items[1]->log);
Assert::same('meeting over', $items[1]->note);

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
Assert::same('paused', $items[0]->status);           // closed tracking is paused
Assert::same('untracked', $items[1]->status);        // break, closed by track replace
Assert::same('XYZ-9', $items[2]->issueId);
Assert::contains('closed at', $items[2]->log);
Assert::same('new', $items[2]->status);
// continuation cloned from ABC-1 (skipping the untracked break in the scan)
Assert::same('ABC-1', $items[3]->issueId);
Assert::same('Implementation', $items[3]->type);
Assert::contains('(done)', $items[3]->log);
Assert::null($items[3]->endedAt);


// === interrupted stays paused on end / track ===

// 10. end on an interrupting record leaves the original paused (not auto-resumed)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
$app->run(['ts', 'end']);
$items = $store->load();
Assert::count(2, $items);                            // no continuation
Assert::same('paused', $items[0]->status);           // ABC-1 still paused
Assert::contains('closed at 2026-05-09 10:50 (end)', $items[1]->log);

// 11. tracking yet another record while interrupting closes the meeting normally
//     and leaves ABC-1 still paused
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'track', 'XYZ-9', 'com']);
$clock->advance('+20 minutes');
$app->run(['ts', 'track', 'DEF-2', 'doc']);          // non-interruption type, normal track-replace
$items = $store->load();
Assert::count(3, $items);
Assert::same('paused', $items[0]->status);           // ABC-1 still paused
Assert::same('new', $items[1]->status);              // meeting closed normally
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
Assert::same('ABC-1', $items[2]->issueId);           // resumed from the paused, not the ended
Assert::same('Implementation', $items[2]->type);
Assert::contains('created at 2026-05-09 10:55 (resume)', $items[2]->log);
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
Assert::contains('closed at 2026-05-09 10:30 (pause)', $items[0]->log);
Assert::same('paused', $items[0]->status);
Assert::same('untracked', $items[1]->status);        // break closed by resume
Assert::contains('closed at 2026-05-09 10:35 (resume)', $items[1]->log);
Assert::same('ABC-1', $items[2]->issueId);
Assert::contains('created at 2026-05-09 10:35 (resume)', $items[2]->log);
Assert::null($items[2]->endedAt);


// === interrupt ===
//
// `interrupt` is `track`'s sibling that unconditionally tags the seam with
// `'interrupted'` when an open record exists — bypassing the type-based
// detection. Use it to declare an interruption explicitly even when the open
// record's type isn't `defaultTrackType` or the new type isn't in
// `interruptionTypes`.

// 14. interrupt pauses the open record regardless of types:
//     open record is Documentation (not default), new type is Implementation
//     (not an interruption type). Plain `track` would NOT have paused.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);          // open: Documentation
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'interrupt', 'XYZ-9']));  // default type: Implementation
$items = $store->load();
Assert::count(2, $items);
Assert::same('Documentation', $items[0]->type);
Assert::same('paused', $items[0]->status);
Assert::contains('closed at 2026-05-09 10:30 (interrupt)', $items[0]->log);
Assert::same('XYZ-9', $items[1]->issueId);
Assert::same('Implementation', $items[1]->type);
Assert::contains('created at 2026-05-09 10:30 (interrupt)', $items[1]->log);

// 15. interrupt + done auto-resumes the original (the whole point of interrupt)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);          // Doc (not default-type)
$clock->advance('+30 minutes');
$app->run(['ts', 'interrupt', 'XYZ-9']);             // Impl (not interruption-type)
$clock->advance('+15 minutes');
$app->run(['ts', 'done']);
$items = $store->load();
Assert::count(3, $items);
Assert::same('paused', $items[0]->status);
Assert::contains('closed at 2026-05-09 10:45 (done)', $items[1]->log);
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Documentation', $items[2]->type);      // cloned from original
Assert::contains('created at 2026-05-09 10:45 (done)', $items[2]->log);
Assert::null($items[2]->endedAt);

// 16. interrupt accepts an explicit <type>
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'interrupt', 'XYZ-9', 'test']);
$items = $store->load();
Assert::same('Test / Review', $items[1]->type);
Assert::same('paused', $items[0]->status);

// 17. interrupt with no open record just creates a new record (nothing to interrupt)
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'interrupt', 'ABC-1']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('ABC-1', $items[0]->issueId);
Assert::same('created at 2026-05-09 10:00 (interrupt)', $items[0]->log);
Assert::same('new', $items[0]->status);

// 18. interrupt on default+default records also pauses the open record (track would NOT)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);                 // Impl
$clock->advance('+15 minutes');
$app->run(['ts', 'interrupt', 'XYZ-9']);             // also Impl by default
$items = $store->load();
Assert::same('Implementation', $items[0]->type);
Assert::same('Implementation', $items[1]->type);
Assert::same('paused', $items[0]->status);
Assert::contains('(interrupt)', $items[1]->log);

// 19. interrupt with unusual issue id is accepted with a warning (same as track)
[$app, $store, , $io] = newApp();
Assert::same(0, $app->run(['ts', 'interrupt', 'not-id']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('not-id', $items[0]->issueId);
Assert::contains('unusual issue id format', $io->getErr());


// === meeting ===
//
// `meeting` is a thin shorthand for `interrupt $defaultMeetingIssue
// $defaultMeetingType` with an optional comment that lands on the new meeting
// record. Force-pauses the open record like `interrupt`; pairs with `done` to
// auto-resume.

// 25. meeting with no open record creates a meeting record using config defaults
[$app, $store, , $io] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'meeting']));
$items = $store->load();
Assert::count(1, $items);
Assert::same('SW-4002', $items[0]->issueId);             // from defaultMeetingIssue
Assert::same('Communication, Meetings, ...', $items[0]->type); // from defaultMeetingType
Assert::same('created at 2026-05-09 10:00 (meeting)', $items[0]->log);
Assert::null($items[0]->endedAt);
Assert::same('new', $items[0]->status);
Assert::same('', $items[0]->note);

// 26. meeting force-pauses the open record regardless of types (like interrupt):
//     open Documentation → meeting flips it to 'paused'.
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1', 'doc']);              // Documentation
$clock->advance('+30 minutes');
Assert::same(0, $app->run(['ts', 'meeting']));
$items = $store->load();
Assert::count(2, $items);
Assert::same('Documentation', $items[0]->type);
Assert::same('paused', $items[0]->status);
Assert::contains('closed at 2026-05-09 10:30 (meeting)', $items[0]->log);
Assert::same('SW-4002', $items[1]->issueId);
Assert::same('Communication, Meetings, ...', $items[1]->type);
Assert::contains('created at 2026-05-09 10:30 (meeting)', $items[1]->log);
Assert::null($items[1]->endedAt);

// 27. meeting with an optional comment lands the comment on the new meeting record
//     (NOT on the closed/paused predecessor — same shape as `pause`)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'meeting', 'standup', 'with', 'team']);
$items = $store->load();
Assert::count(2, $items);
Assert::same('', $items[0]->note);                    // not on the paused predecessor
Assert::same('standup with team', $items[1]->note);   // on the meeting record
Assert::same('paused', $items[0]->status);

// 28. meeting + done auto-resumes the original (the whole point of meeting/interrupt)
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+30 minutes');
$app->run(['ts', 'meeting']);
$clock->advance('+15 minutes');
$app->run(['ts', 'done']);
$items = $store->load();
Assert::count(3, $items);
Assert::same('paused', $items[0]->status);
Assert::same('SW-4002', $items[1]->issueId);
Assert::contains('closed at 2026-05-09 10:45 (done)', $items[1]->log);
Assert::same('ABC-1', $items[2]->issueId);
Assert::same('Implementation', $items[2]->type);
Assert::contains('created at 2026-05-09 10:45 (done)', $items[2]->log);
Assert::null($items[2]->endedAt);

// 29. `me` prefix resolves uniquely to meeting (bare `m` is ambiguous with `mail`)
[$app, $store] = newApp('2026-05-09 10:00');
Assert::same(0, $app->run(['ts', 'me']));
$items = $store->load();
Assert::same('SW-4002', $items[0]->issueId);


// === status field lifecycle ===
//
// Verifies the status field is set/preserved correctly across the commands
// the user expects to map to the 'paused' state, and stays 'new' otherwise.

// 20. fresh tracked record has status='new'
[$app, $store] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
Assert::same('new', $store->load()[0]->status);

// 21. manual pause sets status='paused' on the closed record
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+15 minutes');
$app->run(['ts', 'pause']);
$items = $store->load();
Assert::contains('closed at 2026-05-09 10:15 (pause)', $items[0]->log);
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
//     (only `pause` and the interruption flow flip status).
[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'end']);
Assert::same('new', $store->load()[0]->status);      // 'end' does not pause

[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'track', 'DEF-2', 'doc']);          // plain track-replace
Assert::same('new', $store->load()[0]->status);

[$app, $store, $clock] = newApp('2026-05-09 10:00');
$app->run(['ts', 'track', 'ABC-1']);
$clock->advance('+10 minutes');
$app->run(['ts', 'switch', 'doc']);                  // switch does not pause
Assert::same('new', $store->load()[0]->status);

// 24. legacy records without a `status` field load as status='new'
[$app, $store] = newApp(
    '2026-05-09 10:00',
    [
        new Timeshit\Local\Record(
            id: 1,
            issueId: 'OLD-1',
            type: 'Implementation',
            startedAt: '2026-05-09 09:00',
            endedAt: '2026-05-09 09:30',
            log: '',
        ),
    ],
);
Assert::same('new', $store->load()[0]->status);
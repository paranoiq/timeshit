
todo:
-----

- spawn a http server on 1885 to listen to browser plugin/favelet
- server will periodically save time and auto-end current record if computer was off for some time
- show all week-days in work list. red if empty
- status will show all paused records
- delete command
- sync command
- review/test command
- analyse/design command
- nth: split command to split the same time between two issues
- reverse order in lists, so last time in last in the day


Looking at the current command set, a few common dev scenarios stand out as not (yet) covered:

Editing past records. Every record has a stable id, but note / at / before / after / type only target the latest non-day record. Realising at 16:00 that the morning was the wrong issue, or that a record from
two days ago needs a note, has no clean path. An id-addressable form (e.g. note 42 …, at 42 12:30, type 42 Implementation) plus a delete <id> for accidental tracks would close that gap.

Code review. With the browser plugin watching GitLab MRs, this will be one of the highest-volume signals, but there's no first-class concept for it — it currently has to ride on Test / Review against
whatever issue you happen to be on. A review <MR-or-issue> shortcut (and a way to map MR → issue or to review without a YouTrack issue at all) is worth designing now, since it shapes the plugin's protocol.

Untracked / non-issue work. Reading docs, exploring a library, refining tickets, learning — common dev time that isn't tied to a YouTrack issue. Right now you'd track against a fake id (and trip the
unusual-id warning) or not track at all. Options: a sanctioned "general" pseudo-issue, or accepting empty issue records as a non-error category.

Half-day / partial OOO. day is hardcoded 09:00–17:00 and refuses a second on the same date; half-day vacation, sick afternoon, doctor's appointment fall outside it. A <span>/<from>-<to> form, or letting day
coexist with one half-day record, covers it.

Closing yesterday's forgotten record. If you left a track open overnight, at <full datetime> works but is fiddly and shifts only one boundary. An explicit eod [<time>] that closes the open record at a
sensible workday end (today's or yesterday's) would be ergonomic.

Reports / export. local and all group by week+day, but there's no "weekly total by issue", "by type", or copy-paste-into-timesheet view — and once sync exists, a dry-run "what would push" is the natural
pair.

Sync to YouTrack is the obvious one you've already flagged as not started — the synced / failed statuses and the ✗ marker in all are reserved for it.

The highest-leverage two, IMO: id-addressable edits (because the id machinery is already there and waiting) and a deliberate review concept (because the browser plugin's design hangs off it).


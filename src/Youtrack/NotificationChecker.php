<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use RuntimeException;
use Timeshit\Util\Ansi;
use Timeshit\Util\Io;

use function date;
use function time;

final class NotificationChecker
{
    public function __construct(
        private readonly YoutrackClient $client,
        private readonly CommentsCache $commentsCache,
        private readonly IssueCache $issueCache,
        private readonly Io $io,
        private readonly int $cooldownMinutes,
    ) {}

    /**
     * Checks YouTrack for new comments, assignment changes, and state changes.
     * Returns a list of notifications to display.
     *
     * When $force is true (called from `refresh`), the cooldown is bypassed.
     * On first run (no comments.neon), silently initializes without producing
     * any notifications — subsequent runs diff against this baseline.
     *
     * State/assignee changes are detected by comparing the stored snapshot
     * against the current issue cache; they're most accurate right after
     * `refresh` (when the issue cache is fresh).
     *
     * @return list<Notification>
     */
    public function check(bool $force): array
    {
        if (!$this->issueCache->exists()) {
            return [];
        }

        $isFirstRun = !$this->commentsCache->exists();
        $stored = $this->commentsCache->load();

        if (!$force && !$isFirstRun) {
            $elapsed = time() - $stored['lastChecked'];
            if ($this->cooldownMinutes > 0 && $elapsed < $this->cooldownMinutes * 60) {
                return [];
            }
        }

        $issueData = $this->issueCache->load();
        $issues = $issueData['issues'];
        $user = $issueData['user'];

        $lastCheckedStr = $stored['lastChecked'] > 0
            ? date('Y-m-d H:i', $stored['lastChecked'])
            : '0000-00-00 00:00';

        $notifications = [];
        $newSnapshots = $stored['snapshots'];
        $offline = false;

        foreach ($issues as $issue) {
            $storedSnap = $stored['snapshots'][$issue->id] ?? null;
            $seenIds = $storedSnap !== null ? $storedSnap['seenCommentIds'] : null;

            // Detect assignment and state changes from the cached snapshot.
            // No API call — compares what was stored last check vs current cache.
            if (!$isFirstRun && $storedSnap !== null) {
                if ($storedSnap['assignee'] !== $issue->assignee && $issue->assignee === $user) {
                    $notifications[] = new Notification(
                        kind: Notification::KIND_ASSIGNED,
                        issueId: $issue->id,
                        issueTitle: $issue->title,
                        author: '',
                        payload: "{$storedSnap['assignee']} → {$issue->assignee}",
                    );
                }
                if ($storedSnap['state'] !== $issue->state && $issue->assignee === $user) {
                    $notifications[] = new Notification(
                        kind: Notification::KIND_STATE_CHANGE,
                        issueId: $issue->id,
                        issueTitle: $issue->title,
                        author: '',
                        payload: "{$storedSnap['state']} → {$issue->state}",
                    );
                }
            }

            // Skip comment API call when issue hasn't changed since last check.
            if (!$isFirstRun && $storedSnap !== null && $issue->updated !== '' && $issue->updated <= $lastCheckedStr) {
                $newSnapshots[$issue->id] = [
                    'state' => $issue->state,
                    'assignee' => $issue->assignee,
                    'seenCommentIds' => $seenIds ?? [],
                ];
                continue;
            }

            if ($offline) {
                $newSnapshots[$issue->id] = [
                    'state' => $issue->state,
                    'assignee' => $issue->assignee,
                    'seenCommentIds' => $seenIds ?? [],
                ];
                continue;
            }

            try {
                $comments = $this->client->fetchComments($issue->id);
            } catch (RuntimeException $e) {
                $this->io->err(Ansi::lyellow("Offline ({$e->getMessage()}); skipping comment checks") . "\n");
                $offline = true;
                $newSnapshots[$issue->id] = [
                    'state' => $issue->state,
                    'assignee' => $issue->assignee,
                    'seenCommentIds' => $seenIds ?? [],
                ];
                continue;
            }

            $allIds = [];
            foreach ($comments as $c) {
                $allIds[] = $c->id;
            }

            // First time seeing this issue — initialize without generating notifications.
            if ($isFirstRun || $seenIds === null) {
                $newSnapshots[$issue->id] = [
                    'state' => $issue->state,
                    'assignee' => $issue->assignee,
                    'seenCommentIds' => $allIds,
                ];
                continue;
            }

            $seenSet = [];
            foreach ($seenIds as $id) {
                $seenSet[$id] = true;
            }
            $newSeenIds = $seenIds;
            foreach ($comments as $comment) {
                if (isset($seenSet[$comment->id])) {
                    continue;
                }
                $notifications[] = new Notification(
                    kind: Notification::KIND_COMMENT,
                    issueId: $issue->id,
                    issueTitle: $issue->title,
                    author: $comment->author,
                    payload: $comment->text,
                );
                $newSeenIds[] = $comment->id;
            }

            $newSnapshots[$issue->id] = [
                'state' => $issue->state,
                'assignee' => $issue->assignee,
                'seenCommentIds' => $newSeenIds,
            ];
        }

        $this->commentsCache->save(time(), $newSnapshots);

        return $isFirstRun ? [] : $notifications;
    }
}
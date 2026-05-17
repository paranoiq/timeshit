<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use DateTimeImmutable;
use RuntimeException;
use Timeshit\Util\Ansi;
use Timeshit\Util\Io;
use function count;
use function in_array;
use function sprintf;

final class CachedIssueDataProvider implements IssueDataProvider
{
    public function __construct(
        private readonly IssueCache $issueCache,
        private readonly WorkItemCache $workItemCache,
        private readonly YoutrackClient $client,
        private readonly Io $io,
        private readonly string $youtrackBaseUrl,
        private readonly int $closedIssueRetentionDays,
    ) {}

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    public function loadOrFetch(): array
    {
        if ($this->issueCache->isFresh() && $this->workItemCache->isFresh()) {
            $issuesData = $this->issueCache->load();
            $workItemsData = $this->workItemCache->load();
            $this->io->err(sprintf(
                "Loaded %d issues and %d work items from cache (use 'refresh' to force update)\n\n",
                count($issuesData['issues']),
                count($workItemsData['items']),
            ));

            return [
                'user' => $issuesData['user'],
                'issues' => $issuesData['issues'],
                'workItems' => $workItemsData['items'],
            ];
        }

        try {
            return $this->fetchAndCache();
        } catch (RuntimeException $e) {
            $this->io->err(Ansi::lyellow("Offline ({$e->getMessage()}); using cached data") . "\n");

            return $this->loadFromCacheOrEmpty();
        }
    }

    public function refresh(): void
    {
        $this->fetchAndCache();
    }

    public function ensureIssue(string $issueId): void
    {
        $issues = [];
        $extraIds = [];
        $user = '';
        if ($this->issueCache->exists()) {
            $data = $this->issueCache->load();
            $issues = $data['issues'];
            $extraIds = $data['extraIds'];
            $user = $data['user'];
        }
        foreach ($issues as $existing) {
            if ($existing->id === $issueId) {
                return;
            }
        }
        $extraIds = self::addUnique($extraIds, $issueId);
        try {
            $fetched = $this->client->fetchIssue($issueId);
        } catch (RuntimeException $e) {
            $this->io->err(Ansi::lyellow("Offline ({$e->getMessage()}); '{$issueId}' will be fetched on next refresh") . "\n");
            $this->issueCache->save($user, $issues, $extraIds);

            return;
        }
        if ($fetched === null) {
            $this->io->err(Ansi::lyellow("YouTrack reports '{$issueId}' does not exist") . "\n");
            $this->issueCache->save($user, $issues, $extraIds);

            return;
        }
        $issues[] = $fetched;
        $this->issueCache->save($user, $issues, $extraIds);
    }

    /** @return array<string, string> */
    public function titles(): array
    {
        if (!$this->issueCache->exists()) {
            return [];
        }
        $titleByIssueId = [];
        $issuesData = $this->issueCache->load();
        foreach ($issuesData['issues'] as $issue) {
            $titleByIssueId[$issue->id] = $issue->title;
        }

        return $titleByIssueId;
    }

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    private function fetchAndCache(): array
    {
        $me = $this->client->me();
        $this->io->err(sprintf(
            "Connected to %s as %s (%s)\n",
            $this->youtrackBaseUrl,
            $me['fullName'],
            $me['login'],
        ));

        $previousExtraIds = $this->issueCache->exists() ? $this->issueCache->load()['extraIds'] : [];

        $data = $this->client->fetchMine();
        $issues = $data['issues'];

        if ($this->closedIssueRetentionDays > 0) {
            $cutoff = (new DateTimeImmutable("-{$this->closedIssueRetentionDays} days"))->format('Y-m-d H:i');
            [$issues, $dropped] = self::filterStaleResolved($issues, $cutoff);
            if ($dropped > 0) {
                $this->io->err(sprintf(
                    "Dropped %d closed issue%s resolved before %s\n",
                    $dropped,
                    $dropped === 1 ? '' : 's',
                    $cutoff,
                ));
            }
        }

        $remainingExtraIds = [];
        foreach ($previousExtraIds as $id) {
            if (self::containsId($issues, $id)) {
                continue;
            }
            try {
                $fetched = $this->client->fetchIssue($id);
            } catch (RuntimeException $e) {
                $this->io->err(Ansi::lyellow("Failed to refresh extra issue '{$id}': {$e->getMessage()}") . "\n");
                $remainingExtraIds[] = $id;
                continue;
            }
            if ($fetched === null) {
                $this->io->err(Ansi::lyellow("Extra issue '{$id}' no longer exists; removing from list") . "\n");
                continue;
            }
            $issues[] = $fetched;
            $remainingExtraIds[] = $id;
        }

        $this->issueCache->save($me['login'], $issues, $remainingExtraIds);
        $this->workItemCache->save($me['login'], $data['workItems']);
        $this->io->err(sprintf(
            "Cached %d issues and %d work items\n",
            count($issues),
            count($data['workItems']),
        ));

        return [
            'user' => $me['login'],
            'issues' => $issues,
            'workItems' => $data['workItems'],
        ];
    }

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    private function loadFromCacheOrEmpty(): array
    {
        $user = '';
        $issues = [];
        $workItems = [];
        if ($this->issueCache->exists()) {
            $issuesData = $this->issueCache->load();
            $user = $issuesData['user'];
            $issues = $issuesData['issues'];
        }
        if ($this->workItemCache->exists()) {
            $workItemsData = $this->workItemCache->load();
            if ($user === '') {
                $user = $workItemsData['user'];
            }
            $workItems = $workItemsData['items'];
        }

        return ['user' => $user, 'issues' => $issues, 'workItems' => $workItems];
    }

    /**
     * Drops closed issues whose `resolved` timestamp is strictly before `$cutoff`.
     * Compares the `'Y-m-d H:i'` strings directly — they are lexically sortable.
     *
     * @param list<Issue> $issues
     * @return array{0: list<Issue>, 1: int} kept issues and count dropped
     */
    private static function filterStaleResolved(array $issues, string $cutoff): array
    {
        $kept = [];
        $dropped = 0;
        foreach ($issues as $issue) {
            if ($issue->resolved !== null && $issue->resolved < $cutoff) {
                $dropped++;
                continue;
            }
            $kept[] = $issue;
        }

        return [$kept, $dropped];
    }

    /**
     * @param list<Issue> $issues
     */
    private static function containsId(array $issues, string $id): bool
    {
        foreach ($issues as $issue) {
            if ($issue->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function addUnique(array $ids, string $id): array
    {
        if (in_array($id, $ids, true)) {
            return $ids;
        }
        $ids[] = $id;

        return $ids;
    }
}
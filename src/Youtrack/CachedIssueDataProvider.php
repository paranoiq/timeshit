<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use Timeshit\Util\Io;
use function count;
use function sprintf;

final class CachedIssueDataProvider implements IssueDataProvider
{
    public function __construct(
        private readonly IssueCache $issueCache,
        private readonly WorkItemCache $workItemCache,
        private readonly YoutrackClient $client,
        private readonly Io $io,
        private readonly string $youtrackBaseUrl,
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

        return $this->fetchAndCache();
    }

    public function refresh(): void
    {
        $this->fetchAndCache();
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

        $data = $this->client->fetchMine();
        $this->issueCache->save($me['login'], $data['issues']);
        $this->workItemCache->save($me['login'], $data['workItems']);
        $this->io->err(sprintf(
            "Cached %d issues and %d work items\n",
            count($data['issues']),
            count($data['workItems']),
        ));

        return [
            'user' => $me['login'],
            'issues' => $data['issues'],
            'workItems' => $data['workItems'],
        ];
    }
}
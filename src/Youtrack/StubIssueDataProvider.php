<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

final class StubIssueDataProvider implements IssueDataProvider
{
    /**
     * @param array{user: string, issues: list<Issue>, workItems: list<WorkItem>} $data
     * @param array<string, string> $titles
     */
    public function __construct(
        private array $data = ['user' => '', 'issues' => [], 'workItems' => []],
        private array $titles = [],
    ) {}

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    public function loadOrFetch(): array
    {
        return $this->data;
    }

    public function refresh(): void {}

    public function ensureIssue(string $issueId): void {}

    /** @return array<string, string> */
    public function titles(): array
    {
        return $this->titles;
    }

    /** @param array{user: string, issues: list<Issue>, workItems: list<WorkItem>} $data */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /** @param array<string, string> $titles */
    public function setTitles(array $titles): void
    {
        $this->titles = $titles;
    }
}
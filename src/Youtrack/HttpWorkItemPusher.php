<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

final class HttpWorkItemPusher implements WorkItemPusher
{
    public function __construct(private readonly YoutrackClient $client) {}

    public function push(string $issueId, int $dateMs, int $minutes, string $typeId, string $text): string
    {
        return $this->client->createWorkItem($issueId, $dateMs, $minutes, $typeId, $text);
    }
}
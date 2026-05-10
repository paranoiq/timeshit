<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use Timeshit\Util\Io;
use function count;
use function sprintf;

final class CachedTypeProvider implements TypeProvider
{
    public function __construct(
        private readonly WorkItemTypeCache $cache,
        private readonly YoutrackClient $client,
        private readonly Io $io,
    ) {}

    /** @return list<WorkItemType> */
    public function types(): array
    {
        if ($this->cache->isFresh()) {
            return $this->cache->load();
        }
        $this->refresh();

        return $this->cache->load();
    }

    public function refresh(): void
    {
        $types = $this->client->fetchWorkItemTypes();
        $this->cache->save($types);
        $this->io->err(sprintf("Cached %d work item types\n", count($types)));
    }
}
<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use RuntimeException;
use Timeshit\Util\Ansi;
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
        try {
            $this->refresh();
        } catch (RuntimeException $e) {
            $this->io->err(Ansi::lyellow("Offline ({$e->getMessage()}); using cached work-item types") . "\n");
            if (!$this->cache->exists()) {
                return [];
            }
        }

        return $this->cache->load();
    }

    public function refresh(): void
    {
        $types = $this->client->fetchWorkItemTypes();
        $this->cache->save($types);
        $this->io->err(sprintf("Cached %d work item types\n", count($types)));
    }
}
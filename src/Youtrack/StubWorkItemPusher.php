<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

use RuntimeException;

use function array_slice;
use function count;

final class StubWorkItemPusher implements WorkItemPusher
{
    /** @var list<array{issueId: string, dateMs: int, minutes: int, typeId: string, text: string}> */
    public array $calls = [];

    /** @var list<string|RuntimeException> next return value per call, FIFO */
    private array $queue = [];

    private int $autoCounter = 0;

    /** @param list<string|RuntimeException> $queue */
    public function setResults(array $queue): void
    {
        $this->queue = $queue;
    }

    public function push(string $issueId, int $dateMs, int $minutes, string $typeId, string $text): string
    {
        $this->calls[] = [
            'issueId' => $issueId,
            'dateMs' => $dateMs,
            'minutes' => $minutes,
            'typeId' => $typeId,
            'text' => $text,
        ];
        if ($this->queue !== []) {
            $next = $this->queue[0];
            $this->queue = count($this->queue) > 1 ? array_slice($this->queue, 1) : [];
            if ($next instanceof RuntimeException) {
                throw $next;
            }

            return $next;
        }
        $this->autoCounter++;

        return "wi-{$this->autoCounter}";
    }
}
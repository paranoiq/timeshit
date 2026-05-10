<?php declare(strict_types=1);

namespace Timeshit\Youtrack;

final class StubTypeProvider implements TypeProvider
{
    /** @param list<WorkItemType> $types */
    public function __construct(private array $types) {}

    /** @return list<WorkItemType> */
    public function types(): array
    {
        return $this->types;
    }

    public function refresh(): void {}

    /** @param list<WorkItemType> $types */
    public function setTypes(array $types): void
    {
        $this->types = $types;
    }
}
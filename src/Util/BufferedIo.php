<?php declare(strict_types=1);

namespace Timeshit\Util;

use function array_shift;

final class BufferedIo implements Io
{
    private string $out = '';
    private string $err = '';
    /** @var list<string> */
    private array $inputs;

    /** @param list<string> $inputs lines to feed `readLine()` (without trailing newlines) */
    public function __construct(array $inputs = [])
    {
        $this->inputs = $inputs;
    }

    public function out(string $text): void
    {
        $this->out .= $text;
    }

    public function err(string $text): void
    {
        $this->err .= $text;
    }

    public function readLine(): ?string
    {
        $next = array_shift($this->inputs);

        return $next === null ? null : $next . "\n";
    }

    public function getOut(): string
    {
        return $this->out;
    }

    public function getErr(): string
    {
        return $this->err;
    }

    /** @param list<string> $inputs */
    public function setInputs(array $inputs): void
    {
        $this->inputs = $inputs;
    }

    public function clear(): void
    {
        $this->out = '';
        $this->err = '';
    }
}
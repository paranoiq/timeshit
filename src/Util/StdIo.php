<?php declare(strict_types=1);

namespace Timeshit\Util;

use function fgets;
use function fwrite;
use const STDERR;
use const STDIN;
use const STDOUT;

final class StdIo implements Io
{
    public function out(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    public function err(string $text): void
    {
        fwrite(STDERR, $text);
    }

    public function readLine(): ?string
    {
        $line = fgets(STDIN);

        return $line === false ? null : $line;
    }
}
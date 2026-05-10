<?php declare(strict_types=1);

namespace Timeshit\Util;

interface Io
{
    public function out(string $text): void;

    public function err(string $text): void;

    /** Reads a single line including the trailing newline (matches `fgets`); returns null on EOF. */
    public function readLine(): ?string;
}
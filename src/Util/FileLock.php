<?php declare(strict_types=1);

namespace Timeshit\Util;

use RuntimeException;

use function dirname;
use function fclose;
use function flock;
use function fopen;
use function is_dir;
use function mkdir;

use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;

final class FileLock
{
    /**
     * Runs `$fn` while holding an exclusive lock on `<path>.lock`. Use for
     * writers — blocks both other writers and shared-lock readers.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function exclusive(string $path, callable $fn): mixed
    {
        return self::with($path, LOCK_EX, $fn);
    }

    /**
     * Runs `$fn` while holding a shared lock on `<path>.lock`. Use for
     * readers — multiple readers can hold the lock concurrently; an
     * exclusive writer blocks until they all release.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function shared(string $path, callable $fn): mixed
    {
        return self::with($path, LOCK_SH, $fn);
    }

    /**
     * @template T
     * @param int<0, 7>     $operation
     * @param callable(): T $fn
     * @return T
     */
    private static function with(string $path, int $operation, callable $fn): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create dir: {$dir}");
        }
        $lockPath = $path . '.lock';
        $fp = fopen($lockPath, 'c');
        if ($fp === false) {
            throw new RuntimeException("Failed to open lock file: {$lockPath}");
        }
        if (!flock($fp, $operation)) {
            fclose($fp);
            throw new RuntimeException("Failed to acquire lock: {$lockPath}");
        }
        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
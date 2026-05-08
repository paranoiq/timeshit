<?php

namespace Timeshit;

use FFI;
use function shell_exec;
use function str_contains;

class Console
{

    public const MODE_CANONICAL = 0;
    public const MODE_CBREAK = 1;
    public const MODE_RAW = 2;

    private static ?int $initialTerminalMode = null;

    private static FFI $ffi;

    /**
     * @see https://man7.org/linux/man-pages/man1/stty.1.html
     * @see https://learn.microsoft.com/en-us/windows/console/high-level-console-modes
     *
     * @param self::MODE_* $mode
     */
    public static function setMode(int $mode): void
    {
        if (self::$initialTerminalMode === null) {
            self::$initialTerminalMode = self::getMode();
        }

        if (Os::isWindows()) {
            self::setWindowsMode($mode);
        } else {
            self::setUnixMode($mode);
        }
    }

    /**
     * @return self::MODE_*
     */
    public static function getMode(): int
    {
        return Os::isWindows()
            ? self::getWindowsMode()
            : self::getUnixMode();
    }

    public static function restoreMode(): void
    {
        if (!isset(self::$initialTerminalMode)) {
            return;
        }
        if (Os::isWindows()) {
            self::setWindowsMode(self::$initialTerminalMode);
        } else {
            self::setUnixMode(self::$initialTerminalMode);
        }

        self::$initialTerminalMode = null;
    }

    /**
     * https://pubs.opengroup.org/onlinepubs/9699919799.2013edition/utilities/stty.html
     * @param self::MODE_* $mode
     */
    private static function setUnixMode(int $mode): void
    {
        switch ($mode) {
            case self::MODE_CANONICAL:
                shell_exec('stty cooked echo');
                break;
            case self::MODE_CBREAK:
                shell_exec('stty cbreak -echo');
                break;
            case self::MODE_RAW:
                shell_exec('stty raw -echo');
                break;
        }
    }

    private static function getUnixMode(): int
    {
        $settings = shell_exec('stty -a');

        // Raw: -icanon and -echo
        if (str_contains($settings, '-icanon') && str_contains($settings, '-echo')) {
            return self::MODE_RAW;
        }
        // Cbreak: -icanon but echo is usually on
        if (str_contains($settings, '-icanon')) {
            return self::MODE_CBREAK;
        }

        return self::MODE_CANONICAL;
    }

    /**
     * @return self::MODE_*
     */
    private static function getWindowsMode(): int
    {
        self::initFFI();

        $hInput = self::$ffi->GetStdHandle(-10);
        $mode = self::$ffi->new("DWORD");
        self::$ffi->GetConsoleMode($hInput, FFI::addr($mode));

        $m = $mode->cdata;

        if (($m & 7) === 7) {
            return self::MODE_CANONICAL;
        } elseif (($m & 7) >= 1) {
            return self::MODE_CBREAK;
        } else {
            return self::MODE_RAW;
        }
    }

    /**
     * @param self::MODE_* $mode
     */
    private static function setWindowsMode(int $mode): void
    {
        self::initFFI();

        $hInput = self::$ffi->GetStdHandle(-10); // STD_INPUT_HANDLE
        $currentMode = self::$ffi->new("DWORD");
        self::$ffi->GetConsoleMode($hInput, FFI::addr($currentMode));

        $newMode = $currentMode->cdata;

        switch ($mode) {
            case self::MODE_CANONICAL:
                // Enable: Processed (1), Line (2), Echo (4)
                $newMode |= (1 | 2 | 4);
                break;
            case self::MODE_CBREAK:
                // Enable: Processed (1). Disable: Line (2), Echo (4)
                $newMode |= 1;
                $newMode &= ~(2 | 4);
                break;
            case self::MODE_RAW:
                // Disable: Processed (1), Line (2), Echo (4)
                $newMode &= ~(1 | 2 | 4);
                break;
        }

        self::$ffi->SetConsoleMode($hInput, $newMode);
    }

    private static function initFFI(): void
    {
        if (self::$ffi === null && Os::isWindows()) {
            self::$ffi = FFI::cdef("
                typedef void* HANDLE;
                typedef unsigned long DWORD;
                typedef int BOOL;
                HANDLE GetStdHandle(DWORD nStdHandle);
                BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD *lpMode);
                BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
            ", "kernel32.dll");
        }
    }

    /**
     * Executes a callback within a raw terminal mode.
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withRawMode(callable $callback): mixed
    {
        self::setMode(self::MODE_RAW);

        try {
            return $callback();
        } finally {
            self::restoreMode();
        }
    }

}

<?php declare(strict_types=1);

namespace Timeshit;

final class Ansi
{
    public static function black(string $text): string    { return self::wrap(30, $text); }
    public static function red(string $text): string      { return self::wrap(31, $text); }
    public static function green(string $text): string    { return self::wrap(32, $text); }
    public static function yellow(string $text): string   { return self::wrap(33, $text); }
    public static function blue(string $text): string     { return self::wrap(34, $text); }
    public static function magenta(string $text): string  { return self::wrap(35, $text); }
    public static function cyan(string $text): string     { return self::wrap(36, $text); }
    public static function white(string $text): string    { return self::wrap(37, $text); }

    public static function lblack(string $text): string   { return self::wrap(90, $text); }
    public static function lred(string $text): string     { return self::wrap(91, $text); }
    public static function lgreen(string $text): string   { return self::wrap(92, $text); }
    public static function lyellow(string $text): string  { return self::wrap(93, $text); }
    public static function lblue(string $text): string    { return self::wrap(94, $text); }
    public static function lmagenta(string $text): string { return self::wrap(95, $text); }
    public static function lcyan(string $text): string    { return self::wrap(96, $text); }
    public static function lwhite(string $text): string   { return self::wrap(97, $text); }

    private static function wrap(int $code, string $text): string
    {
        return "\e[{$code}m{$text}\e[0m";
    }
}

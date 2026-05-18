<?php declare(strict_types=1);

namespace Timeshit\Util;

use Closure;

use function mb_strlen;
use function preg_replace;

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

    /**
     * Resolves a color name (matching one of the public color methods above) to
     * the matching first-class-callable wrapper. Returns null for the empty
     * string (= no color) and for any unrecognized name — callers that load
     * names from config should validate against {@see COLOR_NAMES} upstream,
     * so the default arm is a defensive fallback.
     */
    public static function byName(string $name): ?Closure
    {
        return match ($name) {
            'black'    => self::black(...),
            'red'      => self::red(...),
            'green'    => self::green(...),
            'yellow'   => self::yellow(...),
            'blue'     => self::blue(...),
            'magenta'  => self::magenta(...),
            'cyan'     => self::cyan(...),
            'white'    => self::white(...),
            'lblack'   => self::lblack(...),
            'lred'     => self::lred(...),
            'lgreen'   => self::lgreen(...),
            'lyellow'  => self::lyellow(...),
            'lblue'    => self::lblue(...),
            'lmagenta' => self::lmagenta(...),
            'lcyan'    => self::lcyan(...),
            'lwhite'   => self::lwhite(...),
            default    => null,
        };
    }

    /**
     * Public list of color names accepted by {@see byName()} — the 8 standard
     * + 8 bright ANSI 16-colors. Callers parsing config-supplied color names
     * (e.g. `Config::readIssueStates`) validate against this list.
     *
     * @var list<string>
     */
    public const COLOR_NAMES = [
        'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white',
        'lblack', 'lred', 'lgreen', 'lyellow', 'lblue', 'lmagenta', 'lcyan', 'lwhite',
    ];

    /**
     * Underline marker. Uses `\e[24m` (underline-off) instead of the global
     * reset so this composes inside other color wrappers without dropping the
     * outer color when the underlined span ends.
     */
    public static function underline(string $text): string { return "\e[4m{$text}\e[24m"; }

    public static function link(string $url, string $text): string
    {
        return "\e]8;;{$url}\e\\{$text}\e]8;;\e\\";
    }

    public static function length(string $text): int
    {
        return mb_strlen((string) preg_replace('/\e\[[0-9;]*m/', '', $text));
    }

    private static function wrap(int $code, string $text): string
    {
        return "\e[{$code}m{$text}\e[0m";
    }
}

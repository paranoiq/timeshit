<?php declare(strict_types=1);

namespace Timeshit;

use RuntimeException;

use function file_get_contents;
use function is_file;
use function is_string;
use function parse_ini_file;
use function trim;

final class Config
{
    private function __construct(
        public readonly string $youtrackBaseUrl,
        public readonly string $youtrackToken,
    ) {}

    public static function load(string $rootDir): self
    {
        $iniPath = $rootDir . '/config.ini';
        $tokenPath = $rootDir . '/secrets/youtrack-token.txt';

        if (!is_file($iniPath)) {
            throw new RuntimeException("Config file not found: $iniPath");
        }
        if (!is_file($tokenPath)) {
            throw new RuntimeException("Token file not found: $tokenPath");
        }

        $ini = parse_ini_file($iniPath);
        if ($ini === false) {
            throw new RuntimeException("Failed to parse $iniPath");
        }

        $baseUrl = $ini['youtrack_base_url'] ?? null;
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException("Missing youtrack_base_url in $iniPath");
        }

        $token = trim((string)file_get_contents($tokenPath));
        if ($token === '') {
            throw new RuntimeException("Token file is empty: $tokenPath");
        }

        return new self($baseUrl, $token);
    }
}
<?php declare(strict_types=1);

namespace Timeshit;

use Nette\Neon\Neon;
use RuntimeException;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;

final class Config
{
    private const CONFIG_FILE = '/config/config.neon';
    private const SECRETS_FILE = '/config/secrets.neon';

    private function __construct(
        public readonly string $youtrackBaseUrl,
        public readonly string $youtrackToken,
        public readonly string $timezone,
    ) {}

    public static function load(string $rootDir): self
    {
        $cfg = self::readConfig($rootDir);
        $secrets = self::readNeon($rootDir . self::SECRETS_FILE);
        $token = $secrets['youtrackToken'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException(
                "Missing youtrackToken in {$rootDir}" . self::SECRETS_FILE,
            );
        }

        return new self($cfg['youtrackBaseUrl'], $token, $cfg['timezone']);
    }

    /**
     * Reads only the timezone from the config file. Used by callers that need
     * the configured timezone without touching the secrets file (e.g. the CLI
     * dispatcher setting `date_default_timezone_set` for record timestamps).
     */
    public static function timezone(string $rootDir): string
    {
        return self::readConfig($rootDir)['timezone'];
    }

    /** @return array{youtrackBaseUrl: string, timezone: string} */
    private static function readConfig(string $rootDir): array
    {
        $data = self::readNeon($rootDir . self::CONFIG_FILE);
        $baseUrl = $data['youtrackBaseUrl'] ?? null;
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException(
                "Missing youtrackBaseUrl in {$rootDir}" . self::CONFIG_FILE,
            );
        }
        $timezone = $data['timezone'] ?? null;
        if (!is_string($timezone) || $timezone === '') {
            throw new RuntimeException(
                "Missing timezone in {$rootDir}" . self::CONFIG_FILE,
            );
        }

        return ['youtrackBaseUrl' => $baseUrl, 'timezone' => $timezone];
    }

    /** @return array<string, mixed> */
    private static function readNeon(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Config file not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Failed to read: {$path}");
        }
        $decoded = Neon::decode($raw);
        if (!is_array($decoded)) {
            throw new RuntimeException("Not a NEON map: {$path}");
        }

        return $decoded;
    }
}
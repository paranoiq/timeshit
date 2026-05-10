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

    /** @param list<string> $allowedTypes */
    public function __construct(
        public readonly string $youtrackBaseUrl,
        public readonly string $youtrackToken,
        public readonly string $timezone,
        public readonly array $allowedTypes,
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

        return new self($cfg['youtrackBaseUrl'], $token, $cfg['timezone'], $cfg['allowedTypes']);
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

    /** @return array{youtrackBaseUrl: string, timezone: string, allowedTypes: list<string>} */
    private static function readConfig(string $rootDir): array
    {
        $path = $rootDir . self::CONFIG_FILE;
        $data = self::readNeon($path);
        $baseUrl = $data['youtrackBaseUrl'] ?? null;
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException("Missing youtrackBaseUrl in {$path}");
        }
        $timezone = $data['timezone'] ?? null;
        if (!is_string($timezone) || $timezone === '') {
            throw new RuntimeException("Missing timezone in {$path}");
        }
        $allowedTypes = $data['allowedTypes'] ?? null;
        if (!is_array($allowedTypes) || $allowedTypes === []) {
            throw new RuntimeException("Missing or empty allowedTypes in {$path}");
        }
        $names = [];
        foreach ($allowedTypes as $name) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException("Invalid allowedTypes entry in {$path} (expected non-empty strings)");
            }
            $names[] = $name;
        }

        return ['youtrackBaseUrl' => $baseUrl, 'timezone' => $timezone, 'allowedTypes' => $names];
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
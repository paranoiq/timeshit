<?php declare(strict_types=1);

namespace Timeshit;

use Nette\Neon\Neon;
use RuntimeException;

use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function mb_strtolower;

final class Config
{
    private const CONFIG_FILE = '/config/config.neon';
    private const SECRETS_FILE = '/config/secrets.neon';

    /**
     * @param list<string> $allowedTypes
     * @param array<string, list<string>> $typeAliases canonical type name => list of aliases (sparse — only types that have aliases)
     * @param list<string> $interruptionTypes
     */
    public function __construct(
        public readonly string $youtrackBaseUrl,
        public readonly string $youtrackToken,
        public readonly string $timezone,
        public readonly string $defaultIssuePrefix,
        public readonly array $allowedTypes,
        public readonly array $typeAliases,
        public readonly string $defaultTrackType,
        public readonly string $defaultDayType,
        public readonly array $interruptionTypes,
        public readonly string $defaultMeetingType,
        public readonly string $defaultMeetingIssue,
        public readonly string $defaultOutOfOfficeType,
        public readonly string $defaultOutOfOfficeIssue,
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

        return new self(
            $cfg['youtrackBaseUrl'],
            $token,
            $cfg['timezone'],
            $cfg['defaultIssuePrefix'],
            $cfg['allowedTypes'],
            $cfg['typeAliases'],
            $cfg['defaultTrackType'],
            $cfg['defaultDayType'],
            $cfg['interruptionTypes'],
            $cfg['defaultMeetingType'],
            $cfg['defaultMeetingIssue'],
            $cfg['defaultOutOfOfficeType'],
            $cfg['defaultOutOfOfficeIssue'],
        );
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

    /** @return array{youtrackBaseUrl: string, timezone: string, defaultIssuePrefix: string, allowedTypes: list<string>, typeAliases: array<string, list<string>>, defaultTrackType: string, defaultDayType: string, interruptionTypes: list<string>, defaultMeetingType: string, defaultMeetingIssue: string, defaultOutOfOfficeType: string, defaultOutOfOfficeIssue: string} */
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
        $defaultIssuePrefix = $data['defaultIssuePrefix'] ?? null;
        if (!is_string($defaultIssuePrefix) || $defaultIssuePrefix === '') {
            throw new RuntimeException("Missing defaultIssuePrefix in {$path}");
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
        $typeAliases = self::readTypeAliases($data, $names, $path);
        $defaultTrackType = self::requireDefaultType($data, 'defaultTrackType', $names, $path);
        $defaultDayType = self::requireDefaultType($data, 'defaultDayType', $names, $path);
        $defaultMeetingType = self::requireDefaultType($data, 'defaultMeetingType', $names, $path);
        $defaultOutOfOfficeType = self::requireDefaultType($data, 'defaultOutOfOfficeType', $names, $path);
        $interruptionTypes = self::readInterruptionTypes($data, $names, $defaultTrackType, $path);
        $defaultMeetingIssue = $data['defaultMeetingIssue'] ?? null;
        if (!is_string($defaultMeetingIssue) || $defaultMeetingIssue === '') {
            throw new RuntimeException("Missing defaultMeetingIssue in {$path}");
        }
        $defaultOutOfOfficeIssue = $data['defaultOutOfOfficeIssue'] ?? null;
        if (!is_string($defaultOutOfOfficeIssue) || $defaultOutOfOfficeIssue === '') {
            throw new RuntimeException("Missing defaultOutOfOfficeIssue in {$path}");
        }

        return [
            'youtrackBaseUrl' => $baseUrl,
            'timezone' => $timezone,
            'defaultIssuePrefix' => $defaultIssuePrefix,
            'allowedTypes' => $names,
            'typeAliases' => $typeAliases,
            'defaultTrackType' => $defaultTrackType,
            'defaultDayType' => $defaultDayType,
            'interruptionTypes' => $interruptionTypes,
            'defaultMeetingType' => $defaultMeetingType,
            'defaultMeetingIssue' => $defaultMeetingIssue,
            'defaultOutOfOfficeType' => $defaultOutOfOfficeType,
            'defaultOutOfOfficeIssue' => $defaultOutOfOfficeIssue,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowedNames
     * @return array<string, list<string>>
     */
    private static function readTypeAliases(array $data, array $allowedNames, string $path): array
    {
        $value = $data['typeAliases'] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException("Invalid typeAliases in {$path} (expected a map)");
        }
        $seenLower = [];
        foreach ($allowedNames as $name) {
            $seenLower[mb_strtolower($name)] = "canonical type '{$name}'";
        }
        $result = [];
        foreach ($value as $canonical => $aliases) {
            if (!is_string($canonical) || $canonical === '') {
                throw new RuntimeException("Invalid typeAliases key in {$path} (expected non-empty string)");
            }
            if (!in_array($canonical, $allowedNames, true)) {
                throw new RuntimeException(
                    "typeAliases key '{$canonical}' in {$path} is not one of allowedTypes (" . implode(', ', $allowedNames) . ')',
                );
            }
            if (!is_array($aliases)) {
                throw new RuntimeException("Invalid typeAliases value for '{$canonical}' in {$path} (expected a list)");
            }
            $list = [];
            foreach ($aliases as $alias) {
                if (!is_string($alias) || $alias === '') {
                    throw new RuntimeException(
                        "Invalid typeAliases entry under '{$canonical}' in {$path} (expected non-empty strings)",
                    );
                }
                $key = mb_strtolower($alias);
                if (isset($seenLower[$key])) {
                    throw new RuntimeException(
                        "typeAliases entry '{$alias}' under '{$canonical}' in {$path} collides with {$seenLower[$key]}",
                    );
                }
                $seenLower[$key] = "alias '{$alias}' under '{$canonical}'";
                $list[] = $alias;
            }
            if ($list === []) {
                continue;
            }
            $result[$canonical] = $list;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowedNames
     * @return list<string>
     */
    private static function readInterruptionTypes(array $data, array $allowedNames, string $defaultTrackType, string $path): array
    {
        $value = $data['interruptionTypes'] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("Missing interruptionTypes in {$path} (expected a list)");
        }
        $result = [];
        foreach ($value as $name) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException("Invalid interruptionTypes entry in {$path} (expected non-empty strings)");
            }
            if (!in_array($name, $allowedNames, true)) {
                throw new RuntimeException(
                    "interruptionTypes entry '{$name}' in {$path} is not one of allowedTypes (" . implode(', ', $allowedNames) . ')',
                );
            }
            if ($name === $defaultTrackType) {
                throw new RuntimeException(
                    "interruptionTypes in {$path} must not contain defaultTrackType '{$defaultTrackType}'",
                );
            }
            $result[] = $name;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowedNames
     */
    private static function requireDefaultType(array $data, string $key, array $allowedNames, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException("Missing {$key} in {$path}");
        }
        if (!in_array($value, $allowedNames, true)) {
            throw new RuntimeException(
                "{$key} '{$value}' in {$path} is not one of allowedTypes (" . implode(', ', $allowedNames) . ')',
            );
        }

        return $value;
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
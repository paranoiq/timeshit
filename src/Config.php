<?php declare(strict_types=1);

namespace Timeshit;

use Nette\Neon\Neon;
use RuntimeException;

use function array_keys;
use function array_merge;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
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
     * @param list<CustomCommand> $customCommands user-defined shortcuts; order preserved for help rendering
     * @param array<string, string> $commandAliases alias name => canonical command name (builtin or custom)
     * @param list<string> $customCommandWarnings non-fatal warnings collected while parsing `customCommands` (e.g. a child defined a field its parent does not accept); the offending commands are dropped from `$customCommands`
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
        public readonly string $defaultDayIssue,
        public readonly array $interruptionTypes,
        public readonly array $customCommands,
        public readonly array $commandAliases,
        public readonly string $editor,
        public readonly int $closedIssueRetentionDays,
        public readonly int $port,
        public readonly array $customCommandWarnings = [],
    ) {}

    public static function load(string $rootDir): self
    {
        $cfg = self::readConfig($rootDir);
        $secretsPath = $rootDir . self::SECRETS_FILE;
        $secrets = self::readNeon($secretsPath);
        $baseUrl = $secrets['youtrackBaseUrl'] ?? null;
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException("Missing youtrackBaseUrl in {$secretsPath}");
        }
        $token = $secrets['youtrackToken'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException("Missing youtrackToken in {$secretsPath}");
        }

        return new self(
            $baseUrl,
            $token,
            $cfg['timezone'],
            $cfg['defaultIssuePrefix'],
            $cfg['allowedTypes'],
            $cfg['typeAliases'],
            $cfg['defaultTrackType'],
            $cfg['defaultDayType'],
            $cfg['defaultDayIssue'],
            $cfg['interruptionTypes'],
            $cfg['customCommands'],
            $cfg['commandAliases'],
            $cfg['editor'],
            $cfg['closedIssueRetentionDays'],
            $cfg['port'],
            $cfg['customCommandWarnings'],
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

    /** @return array{timezone: string, defaultIssuePrefix: string, allowedTypes: list<string>, typeAliases: array<string, list<string>>, defaultTrackType: string, defaultDayType: string, defaultDayIssue: string, interruptionTypes: list<string>, customCommands: list<CustomCommand>, commandAliases: array<string, string>, customCommandWarnings: list<string>, editor: string, closedIssueRetentionDays: int, port: int} */
    private static function readConfig(string $rootDir): array
    {
        $path = $rootDir . self::CONFIG_FILE;
        $data = self::readNeon($path);
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
        $interruptionTypes = self::readInterruptionTypes($data, $names, $defaultTrackType, $path);
        $customCommandWarnings = [];
        $customCommands = self::readCustomCommands($data, $names, $path, $customCommandWarnings);
        $customNames = [];
        foreach ($customCommands as $c) {
            $customNames[] = $c->name;
        }
        $commandAliases = self::readCommandAliases($data, $customNames, $path);
        $defaultDayIssue = $data['defaultDayIssue'] ?? null;
        if (!is_string($defaultDayIssue) || $defaultDayIssue === '') {
            throw new RuntimeException("Missing defaultDayIssue in {$path}");
        }
        $editor = $data['editor'] ?? null;
        if (!is_string($editor) || $editor === '') {
            throw new RuntimeException("Missing editor in {$path}");
        }
        $closedIssueRetentionDays = $data['closedIssueRetentionDays'] ?? 90;
        if (!is_int($closedIssueRetentionDays) || $closedIssueRetentionDays < 0) {
            throw new RuntimeException("Invalid closedIssueRetentionDays in {$path} (expected non-negative int; 0 disables filtering)");
        }
        $port = $data['port'] ?? 1985;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException("Invalid port in {$path} (expected int in 1..65535)");
        }

        return [
            'timezone' => $timezone,
            'defaultIssuePrefix' => $defaultIssuePrefix,
            'allowedTypes' => $names,
            'typeAliases' => $typeAliases,
            'defaultTrackType' => $defaultTrackType,
            'defaultDayType' => $defaultDayType,
            'defaultDayIssue' => $defaultDayIssue,
            'interruptionTypes' => $interruptionTypes,
            'customCommands' => $customCommands,
            'commandAliases' => $commandAliases,
            'customCommandWarnings' => $customCommandWarnings,
            'editor' => $editor,
            'closedIssueRetentionDays' => $closedIssueRetentionDays,
            'port' => $port,
        ];
    }

    /**
     * Fields each parent action command actually receives from its child
     * custom command. Anything outside this list is silently ignored by the
     * dispatcher, so configuring it would be a typo — `readCustomCommands`
     * warns and drops the offending custom command.
     *
     * @var array<string, list<string>>
     */
    private const PARENT_FIELDS = [
        'track'     => ['type', 'issue', 'note'],
        'interrupt' => ['type', 'issue', 'note'],
        'put'       => ['type', 'issue', 'note', 'span'],
        'grab'      => ['type', 'issue', 'note', 'span'],
        'days'      => ['type', 'issue', 'note', 'day'],
        'switch'    => ['type'],
        'skip'      => ['span'],
        'pause'     => ['note'],
        'done'      => ['note'],
        'end'       => ['note'],
        'resume'    => [],
        'continue'  => [],
    ];

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowedNames
     * @param list<string> $warnings collected non-fatal issues; offending custom commands are dropped
     * @return list<CustomCommand>
     */
    private static function readCustomCommands(array $data, array $allowedNames, string $path, array &$warnings): array
    {
        $value = $data['customCommands'] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException("Invalid customCommands in {$path} (expected a map)");
        }
        $result = [];
        $seenLower = [];
        foreach ($value as $name => $spec) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException("Invalid customCommands key in {$path} (expected non-empty string)");
            }
            $key = mb_strtolower($name);
            if (isset($seenLower[$key])) {
                throw new RuntimeException("Duplicate customCommands entry '{$name}' in {$path}");
            }
            $seenLower[$key] = true;
            if (!is_array($spec)) {
                throw new RuntimeException("Invalid customCommands entry '{$name}' in {$path} (expected a map)");
            }
            $parent = $spec['parent'] ?? null;
            $allowedParents = array_keys(self::PARENT_FIELDS);
            if (!in_array($parent, $allowedParents, true)) {
                throw new RuntimeException(
                    "customCommands.{$name}.parent in {$path} must be one of " . implode(', ', $allowedParents),
                );
            }
            $typeRequired = in_array($parent, ['track', 'interrupt', 'put', 'grab', 'days', 'switch'], true);
            $type = self::optString($spec, 'type', "customCommands.{$name}.type", $path);
            if ($typeRequired) {
                if ($type === '') {
                    throw new RuntimeException("Missing customCommands.{$name}.type in {$path}");
                }
                if (!in_array($type, $allowedNames, true)) {
                    throw new RuntimeException(
                        "customCommands.{$name}.type '{$type}' in {$path} is not one of allowedTypes ("
                        . implode(', ', $allowedNames) . ')',
                    );
                }
            }
            $issue = self::optString($spec, 'issue', "customCommands.{$name}.issue", $path);
            $note = self::optString($spec, 'note', "customCommands.{$name}.note", $path);
            $span = self::optString($spec, 'span', "customCommands.{$name}.span", $path);
            $day = self::optString($spec, 'day', "customCommands.{$name}.day", $path);

            $accepted = self::PARENT_FIELDS[$parent];
            $extras = [];
            foreach (['type' => $type, 'issue' => $issue, 'note' => $note, 'span' => $span, 'day' => $day] as $field => $fieldValue) {
                if ($fieldValue !== '' && !in_array($field, $accepted, true)) {
                    $extras[] = $field;
                }
            }
            if ($extras !== []) {
                $warnings[] = "customCommands.{$name}: parent '{$parent}' does not accept "
                    . implode(', ', $extras)
                    . " — command disabled.";
                continue;
            }

            $result[] = new CustomCommand(
                name: $name,
                parent: $parent,
                type: $type,
                issue: $issue,
                note: $note,
                span: $span,
                day: $day,
            );
        }

        return $result;
    }

    /**
     * Parses the `commandAliases:` map. Each key is an alias (CLI name) and
     * each value is the canonical command it resolves to (a builtin or a
     * known custom). Aliases cannot collide with builtin or custom command
     * names, and canonicals must be real commands (no alias-to-alias chains).
     *
     * @param array<string, mixed> $data
     * @param list<string> $customNames
     * @return array<string, string>
     */
    private static function readCommandAliases(array $data, array $customNames, string $path): array
    {
        $value = $data['commandAliases'] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException("Invalid commandAliases in {$path} (expected a map)");
        }
        $builtins = Help::BUILTIN_COMMAND_NAMES;
        $allCommands = array_merge($builtins, $customNames);
        $seenLower = [];
        foreach ($allCommands as $name) {
            $seenLower[mb_strtolower($name)] = "command '{$name}'";
        }
        $result = [];
        foreach ($value as $alias => $canonical) {
            if (!is_string($alias) || $alias === '') {
                throw new RuntimeException("Invalid commandAliases key in {$path} (expected non-empty string)");
            }
            if (!is_string($canonical) || $canonical === '') {
                throw new RuntimeException("Invalid commandAliases.{$alias} in {$path} (expected non-empty string)");
            }
            $key = mb_strtolower($alias);
            if (isset($seenLower[$key])) {
                throw new RuntimeException(
                    "commandAliases entry '{$alias}' in {$path} collides with {$seenLower[$key]}",
                );
            }
            if (!in_array($canonical, $allCommands, true)) {
                throw new RuntimeException(
                    "commandAliases.{$alias} in {$path} points at unknown command '{$canonical}'",
                );
            }
            $seenLower[$key] = "alias '{$alias}'";
            $result[$alias] = $canonical;
        }

        return $result;
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

    /** @param array<int|string, mixed> $spec */
    private static function optString(array $spec, string $key, string $label, string $path): string
    {
        $value = $spec[$key] ?? '';
        if (!is_string($value)) {
            throw new RuntimeException("Invalid {$label} in {$path} (expected a string)");
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
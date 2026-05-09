<?php declare(strict_types=1);

namespace Timeshit;

use Collator;
use DateTimeImmutable;
use Exception;
use RuntimeException;
use Throwable;
use Timeshit\Local\Record;
use Timeshit\Local\Store;
use Timeshit\View\IssuesView;
use Timeshit\View\RecordsView;
use Timeshit\View\WorkView;
use Timeshit\Youtrack\Issue;
use Timeshit\Youtrack\IssueCache;
use Timeshit\Youtrack\WorkItem;
use Timeshit\Youtrack\WorkItemCache;
use Timeshit\Youtrack\WorkItemType;
use Timeshit\Youtrack\WorkItemTypeCache;
use Timeshit\Youtrack\YoutrackClient;

use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function date;
use function file_exists;
use function fprintf;
use function fwrite;
use function implode;
use function intdiv;
use function max;
use function preg_match;
use function str_repeat;
use function str_starts_with;
use function mb_strtolower;
use function strtoupper;
use function usort;

use const STDERR;

final class App
{

    private const ISSUES_CACHE_FILE = '/data/issues.json';
    private const WORK_ITEMS_CACHE_FILE = '/data/work-items.json';
    private const WORK_ITEM_TYPES_FILE = '/data/work-item-types.json';
    private const RECORDS_FILE = '/data/records.json';

    private const TRACK_DEFAULT_TYPE = 'Implementation';
    private const DAY_DEFAULT_TYPE = 'Out of office';

    /**
     * The whitelist of YouTrack work-item types we currently allow on local
     * records. Anything outside this list is rejected by `matchType`.
     */
    public const ALLOWED_TYPES = [
        'Analyses / Design',
        'Communication, Meetings, ...',
        'Documentation',
        'Implementation',
        'Out of office',
        'Test / Review',
    ];

    public function __construct(private readonly string $rootDir) {}

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? null;

        if ($command === null || $command === 'help' || $command === '-h' || $command === '--help') {
            $this->printHelp();

            return 0;
        }

        try {
            match ($command) {
                'issues' => $this->cmdIssues(Config::load($this->rootDir)),
                'work' => $this->cmdWork(Config::load($this->rootDir)),
                'records' => $this->cmdRecords(Config::load($this->rootDir)),
                'refresh' => $this->cmdRefresh(Config::load($this->rootDir)),
                'track' => $this->cmdTrack($argv[2] ?? null, $argv[3] ?? null),
                'day' => $this->cmdDay($argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null),
                'checkout' => $this->cmdCheckout($argv[2] ?? null, $argv[3] ?? null),
                'type' => $this->cmdType($argv[2] ?? null),
                'types' => $this->cmdTypes(),
                'switch' => $this->cmdSwitch($argv[2] ?? null),
                'pause' => $this->cmdPause(self::restArgs($argv)),
                'resume' => $this->cmdResume(self::restArgs($argv)),
                'end' => $this->cmdEnd(self::restArgs($argv)),
                'comment' => $this->cmdComment(self::restArgs($argv)),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, Ansi::red("Error: " . $e->getMessage()) . "\n");

            return 1;
        }

        return 0;
    }

    private function unknownCommand(string $command): never
    {
        fwrite(STDERR, Ansi::red("Unknown command: {$command}") . "\n\n");
        $this->printHelp();
        exit(1);
    }

    private function printHelp(): void
    {
        $cmd = static fn(string $name): string => Ansi::lgreen($name);
        $req = static fn(string $name): string => Ansi::yellow("<{$name}>");
        $opt = static fn(string $name): string => Ansi::lblack("[") . Ansi::yellow("<{$name}>") . Ansi::lblack("]");

        $groups = [
            'Lists' => [
                [$cmd('issues'),   '', 'List YouTrack issues you are involved in (cached for 24h)'],
                [$cmd('work'),     '', 'List your YouTrack work items grouped by week and day (cached for 24h)'],
                [$cmd('records'),  '', 'List locally tracked records not yet synced to YouTrack'],
                [$cmd('types'),    '', 'List the YouTrack work-item types (cached for 24h)'],
            ],
            'Actions' => [
                [$cmd('track'),    $req('issue') . ' ' . $opt('type'),
                    "Manually switch local time tracking to " . $req('issue')
                    . " (type defaults to \"" . Ansi::lgreen(self::TRACK_DEFAULT_TYPE) . "\")"],
                [$cmd('day'),      $req('issue') . ' ' . $opt('date') . ' ' . $opt('type'),
                    "Log a full 8h day (date default to today; accepts day-of-month int, "
                    . Ansi::lgreen('y|yes|yesterday') . " etc.; type defaults to \"" . Ansi::lyellow(self::DAY_DEFAULT_TYPE) . '"'],
                [$cmd('type'),     $req('type'), 'Change the type of the currently tracked entry'],
                [$cmd('switch'),   $req('type'), 'End the current entry and open a new one with the same issue/branch/repo and the given ' . $req('type')],
                [$cmd('comment'),  $req('comment'), 'Set a comment on the currently tracked entry'],
                [$cmd('pause'),    $opt('comment'), 'Pause the currently tracked record (optional ' . $req('comment') . ' attached to the record)'],
                [$cmd('resume'),   $opt('comment'), 'Resume tracking from the most recent record (optional ' . $req('comment') . ' on the new record)'],
                [$cmd('end'),      $opt('comment'), 'End the currently tracked entry (optional ' . $req('comment') . ' attached to the record)'],
                [$cmd('refresh'),  '', 'Force-refresh all caches from YouTrack and print stats'],
            ],
            'Triggers' => [
                [$cmd('checkout'), $req('branch') . ' ' . $req('repo'),
                    "Switch tracking on git checkout (called from " . $cmd('hooks/post-checkout') . ")"],
            ],
        ];

        $nameWidth = 0;
        $argsWidth = 0;
        foreach ($groups as $rows) {
            foreach ($rows as [$name, $args]) {
                $nameWidth = max($nameWidth, Ansi::length($name));
                $argsWidth = max($argsWidth, Ansi::length($args));
            }
        }

        echo Ansi::lwhite('timeshit') . ' ' . Ansi::lblack('— personal time tracker for YouTrack + Git +the  GitLab') . "\n\n";
        echo "Usage: " . $cmd('timeshit.php') . " " . $req('command') . " " . $opt('args') . "\n";
        foreach ($groups as $title => $rows) {
            echo "\n" . Ansi::lwhite($title) . ":\n";
            foreach ($rows as [$name, $args, $desc]) {
                echo "  " . $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2)
                    . $args . str_repeat(' ', $argsWidth - Ansi::length($args) + 2)
                    . $desc . "\n";
            }
        }
    }

    private function cmdIssues(Config $config): void
    {
        $data = $this->loadOrFetch($config);
        (new IssuesView($config->youtrackBaseUrl, $data['user']))->render($data['issues'], $data['workItems']);
    }

    private function cmdWork(Config $config): void
    {
        $data = $this->loadOrFetch($config);
        (new WorkView($config->youtrackBaseUrl))->render($data['workItems'], $data['issues']);
    }

    private function cmdRecords(Config $config): void
    {
        $items = (new Store($this->rootDir . self::RECORDS_FILE))->load();
        $titleByIssueId = [];
        $issueCachePath = $this->rootDir . self::ISSUES_CACHE_FILE;
        if (file_exists($issueCachePath)) {
            $issuesData = (new IssueCache($issueCachePath))->load();
            foreach ($issuesData['issues'] as $issue) {
                $titleByIssueId[$issue->id] = $issue->title;
            }
        }
        (new RecordsView($config->youtrackBaseUrl))->render($items, $titleByIssueId);
    }

    private function cmdTypes(): void
    {
        $types = $this->loadOrFetchTypes();
        $collator = new Collator('cs_CZ');
        $collator->setStrength(Collator::SECONDARY);
        usort($types, static function (WorkItemType $a, WorkItemType $b) use ($collator): int {
            $cmp = $collator->compare($a->name, $b->name);

            return $cmp === false ? 0 : $cmp;
        });
        $nameWidth = 0;
        foreach ($types as $t) {
            $nameWidth = max($nameWidth, Ansi::length($t->name));
        }
        foreach ($types as $t) {
            $name = in_array($t->name, self::ALLOWED_TYPES, true) ? Ansi::lgreen($t->name) : $t->name;
            echo $name . str_repeat(' ', $nameWidth - Ansi::length($name) + 2) . Ansi::lblack($t->id) . "\n";
        }
    }

    private function cmdRefresh(Config $config): void
    {
        $this->fetchAndCache($config);
        $this->fetchAndCacheTypes($config);
    }

    private function cmdType(?string $newType): void
    {
        $matched = $this->resolveType('type', $newType, null);
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->changeOpenType($matched);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('type: no open tracking entry to update');
        }
        if (!$result['changed']) {
            return;
        }
        fprintf(
            STDERR,
            "Changed type of %s from %s to %s\n",
            $item->issueId,
            (string) $result['previousType'],
            $matched,
        );
    }

    private function cmdSwitch(?string $newType): void
    {
        $matched = $this->resolveType('switch', $newType, null);
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null || !$last->isOpen()) {
            throw new RuntimeException('switch: no open tracking entry');
        }
        $trigger = 'switched';
        $next = new Record(
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $matched,
            startedAt: date('c'),
            startTrigger: $trigger,
            endedAt: null,
            endTrigger: null,
        );
        $result = $store->track($next, $trigger);
        if (!$result['started']) {
            return;
        }
        $stopped = $result['stopped'];
        if ($stopped !== null && $stopped->endedAt !== null) {
            fprintf(
                STDERR,
                "Stopped %s (%s) after %s\n",
                $stopped->issueId,
                $stopped->type,
                self::formatDuration($stopped->startedAt, $stopped->endedAt),
            );
        }
        $onBranch = $next->branch === null ? '' : " ({$next->branch})";
        $inRepo = $next->repo === '' ? '' : " in {$next->repo}";
        fprintf(STDERR, "Tracking %s%s%s as %s\n", $next->issueId, $onBranch, $inRepo, $matched);
    }

    private function cmdEnd(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->endOpen(date('c'), 'ended', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('end: no open tracking entry');
        }
        $endedAt = $item->endedAt ?? '';
        fprintf(
            STDERR,
            "Stopped %s after %s\n",
            $item->issueId,
            self::formatDuration($item->startedAt, $endedAt),
        );
        if ($item->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $item->comment);
        }
    }

    private function cmdPause(?string $comment): void
    {
        $resolved = $comment === '' ? null : $comment;
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->endOpen(date('c'), 'paused', $resolved);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('pause: no open tracking entry');
        }
        $endedAt = $item->endedAt ?? '';
        fprintf(
            STDERR,
            "Paused %s after %s\n",
            $item->issueId,
            self::formatDuration($item->startedAt, $endedAt),
        );
        if ($item->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $item->comment);
        }
    }

    private function cmdResume(?string $comment): void
    {
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $items = $store->load();
        $last = $items === [] ? null : $items[count($items) - 1];
        if ($last === null) {
            throw new RuntimeException('resume: no record to resume');
        }
        if ($last->isOpen()) {
            throw new RuntimeException('resume: a record is already open');
        }
        $next = new Record(
            issueId: $last->issueId,
            branch: $last->branch,
            repo: $last->repo,
            type: $last->type,
            startedAt: date('c'),
            startTrigger: 'resumed',
            endedAt: null,
            endTrigger: null,
            comment: $comment ?? '',
        );
        $store->track($next, 'resumed');
        $onBranch = $next->branch === null ? '' : " ({$next->branch})";
        fprintf(STDERR, "Resumed %s%s as %s\n", $next->issueId, $onBranch, $next->type);
        if ($next->comment !== '') {
            fprintf(STDERR, "Comment: %s\n", $next->comment);
        }
    }

    private function cmdComment(?string $comment): void
    {
        if ($comment === null || $comment === '') {
            throw new RuntimeException('comment: missing <text>');
        }
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->commentOpen($comment);
        $item = $result['item'];
        if ($item === null) {
            throw new RuntimeException('comment: no open tracking entry');
        }
        if (!$result['changed']) {
            return;
        }
        fprintf(STDERR, "Comment on %s: %s\n", $item->issueId, $item->comment);
    }

    private function cmdTrack(?string $issue, ?string $type): void
    {
        $issueId = self::requireIssueId('track', $issue);
        $resolvedType = $this->resolveType('track', $type, self::TRACK_DEFAULT_TYPE);
        $this->startRecord($issueId, null, '', $resolvedType, 'manual');
    }

    private function cmdDay(?string $issue, ?string $date, ?string $type): void
    {
        $issueId = self::requireIssueId('day', $issue);
        $when = self::resolveDate($date);
        $resolvedType = $this->resolveType('day', $type, self::DAY_DEFAULT_TYPE);
        $store = new Store($this->rootDir . self::RECORDS_FILE);
        $dayKey = $when->format('Y-m-d');
        foreach ($store->load() as $existing) {
            if ($existing->startTrigger !== 'day') {
                continue;
            }
            if ((new DateTimeImmutable($existing->startedAt))->format('Y-m-d') === $dayKey) {
                throw new RuntimeException(
                    "day: a full-day record already exists on {$dayKey} ({$existing->issueId}, {$existing->type})",
                );
            }
        }
        $start = $when->setTime(9, 0);
        $end = $when->setTime(17, 0);
        $record = new Record(
            issueId: $issueId,
            branch: null,
            repo: '',
            type: $resolvedType,
            startedAt: $start->format('c'),
            startTrigger: 'day',
            endedAt: $end->format('c'),
            endTrigger: 'day',
        );
        $store->appendClosed($record);
        fprintf(
            STDERR,
            "Logged %s for %s as %s (8h)\n",
            $issueId,
            $dayKey,
            $resolvedType,
        );
    }

    private function cmdCheckout(?string $branch, ?string $repo): void
    {
        if ($branch === null || $branch === '') {
            throw new RuntimeException('checkout: missing <branch>');
        }
        if ($repo === null || $repo === '') {
            throw new RuntimeException('checkout: missing <repo>');
        }
        $this->startRecord(self::extractIssueId($branch), $branch, $repo, null, 'checkout');
    }

    private function startRecord(string $issueId, ?string $branch, string $repo, ?string $type, string $trigger): void
    {
        $resolvedType = $type === null || $type === '' ? self::TRACK_DEFAULT_TYPE : $type;
        $next = new Record(
            issueId: $issueId,
            branch: $branch,
            repo: $repo,
            type: $resolvedType,
            startedAt: date('c'),
            startTrigger: $trigger,
            endedAt: null,
            endTrigger: null,
        );
        $result = (new Store($this->rootDir . self::RECORDS_FILE))->track($next, $trigger);
        if (!$result['started']) {
            return;
        }
        $stopped = $result['stopped'];
        if ($stopped !== null && $stopped->endedAt !== null) {
            fprintf(
                STDERR,
                "Stopped %s after %s\n",
                $stopped->issueId,
                self::formatDuration($stopped->startedAt, $stopped->endedAt),
            );
        }
        $onBranch = $branch === null ? '' : " ({$branch})";
        $inRepo = $repo === '' ? '' : " in {$repo}";
        fprintf(STDERR, "Tracking %s%s%s as %s\n", $next->issueId, $onBranch, $inRepo, $resolvedType);
    }

    /** @return list<WorkItemType> */
    private function loadOrFetchTypes(): array
    {
        $cache = new WorkItemTypeCache($this->rootDir . self::WORK_ITEM_TYPES_FILE);
        if ($cache->isFresh()) {
            return $cache->load();
        }
        $this->fetchAndCacheTypes(Config::load($this->rootDir));

        return $cache->load();
    }

    private function fetchAndCacheTypes(Config $config): void
    {
        $cache = new WorkItemTypeCache($this->rootDir . self::WORK_ITEM_TYPES_FILE);
        $client = new YoutrackClient($config->youtrackBaseUrl, $config->youtrackToken);
        $types = $client->fetchWorkItemTypes();
        $cache->save($types);
        fprintf(STDERR, "Cached %d work item types\n", count($types));
    }

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    private function loadOrFetch(Config $config): array
    {
        $issueCache = new IssueCache($this->rootDir . self::ISSUES_CACHE_FILE);
        $workItemCache = new WorkItemCache($this->rootDir . self::WORK_ITEMS_CACHE_FILE);
        if ($issueCache->isFresh() && $workItemCache->isFresh()) {
            $issuesData = $issueCache->load();
            $workItemsData = $workItemCache->load();
            fprintf(
                STDERR,
                "Loaded %d issues and %d work items from cache (use 'refresh' to force update)\n\n",
                count($issuesData['issues']),
                count($workItemsData['items']),
            );

            return [
                'user' => $issuesData['user'],
                'issues' => $issuesData['issues'],
                'workItems' => $workItemsData['items'],
            ];
        }

        return $this->fetchAndCache($config);
    }

    /** @return array{user: string, issues: list<Issue>, workItems: list<WorkItem>} */
    private function fetchAndCache(Config $config): array
    {
        $issueCache = new IssueCache($this->rootDir . self::ISSUES_CACHE_FILE);
        $workItemCache = new WorkItemCache($this->rootDir . self::WORK_ITEMS_CACHE_FILE);
        $client = new YoutrackClient($config->youtrackBaseUrl, $config->youtrackToken);

        $me = $client->me();
        fprintf(
            STDERR,
            "Connected to %s as %s (%s)\n",
            $config->youtrackBaseUrl,
            $me['fullName'],
            $me['login'],
        );

        $data = $client->fetchMine();
        $issueCache->save($me['login'], $data['issues']);
        $workItemCache->save($me['login'], $data['workItems']);
        fprintf(
            STDERR,
            "Cached %d issues and %d work items\n",
            count($data['issues']),
            count($data['workItems']),
        );

        return [
            'user' => $me['login'],
            'issues' => $data['issues'],
            'workItems' => $data['workItems'],
        ];
    }

    /** @param array<int, string> $argv */
    private static function restArgs(array $argv): ?string
    {
        $rest = array_slice($argv, 2);
        if ($rest === []) {
            return null;
        }

        return implode(' ', $rest);
    }

    /**
     * Resolves a type by exact (case-insensitive) match, falling back to a
     * unique case-insensitive prefix. Throws on missing input (when no
     * default is given), ambiguous prefix, or unknown input.
     */
    private function resolveType(string $cmd, ?string $input, ?string $default): string
    {
        if ($input === null || $input === '') {
            if ($default === null) {
                throw new RuntimeException("{$cmd}: missing <type>");
            }

            return $default;
        }

        return self::matchType($cmd, $input, $this->loadOrFetchTypes());
    }

    /** @param list<WorkItemType> $types */
    public static function matchType(string $cmd, string $input, array $types): string
    {
        $allowed = array_values(array_filter(
            $types,
            static fn(WorkItemType $t): bool => in_array($t->name, self::ALLOWED_TYPES, true),
        ));
        $needle = mb_strtolower($input);
        foreach ($allowed as $type) {
            if (mb_strtolower($type->name) === $needle) {
                return $type->name;
            }
        }
        $matches = [];
        foreach ($allowed as $type) {
            if (str_starts_with(mb_strtolower($type->name), $needle)) {
                $matches[] = $type->name;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new RuntimeException("{$cmd}: ambiguous type '{$input}', could be: " . implode(', ', $matches));
        }
        $names = [];
        foreach ($allowed as $t) {
            $names[] = $t->name;
        }
        throw new RuntimeException("{$cmd}: unknown type '{$input}'. Allowed: " . implode(', ', $names));
    }

    public static function requireIssueId(string $cmd, ?string $issue): string
    {
        if ($issue === null || $issue === '') {
            throw new RuntimeException("{$cmd}: missing <issue>");
        }
        if (preg_match('/^[A-Za-z]+-\d+$/', $issue) !== 1) {
            throw new RuntimeException("{$cmd}: invalid issue '{$issue}' (expected format like ABC-123)");
        }

        return strtoupper($issue);
    }

    private static function extractIssueId(string $branch): string
    {
        if (preg_match('/^([A-Za-z]{1,3}-\d+)\b/', $branch, $m) === 1) {
            return strtoupper($m[1]);
        }

        return $branch;
    }

    public static function resolveDate(?string $input): DateTimeImmutable
    {
        $original = $input === null || $input === '' ? 'today' : $input;
        if (preg_match('/^\d+$/', $original) === 1) {
            return self::dayOfCurrentMonth((int) $original, $original);
        }
        $offsets = [
            'today' => 0,
            'yesterday' => -1,
            'tomorrow' => 1,
            'ereyesterday' => -2,
            'overmorrow' => 2,
        ];
        $needle = mb_strtolower($original);
        $matches = [];
        foreach (array_keys($offsets) as $keyword) {
            if (str_starts_with($keyword, $needle)) {
                $matches[] = $keyword;
            }
        }
        if (count($matches) === 1) {
            return (new DateTimeImmutable('today'))->modify($offsets[$matches[0]] . ' days');
        }
        if (count($matches) > 1) {
            throw new RuntimeException("day: ambiguous date '{$original}', could be: " . implode(', ', $matches));
        }
        try {
            return new DateTimeImmutable($original);
        } catch (Exception) {
            throw new RuntimeException("day: invalid date '{$original}'");
        }
    }

    private static function dayOfCurrentMonth(int $day, string $original): DateTimeImmutable
    {
        $today = new DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $month = (int) $today->format('m');
        $resolved = $today->setDate($year, $month, $day);
        if ((int) $resolved->format('j') !== $day || (int) $resolved->format('n') !== $month) {
            throw new RuntimeException("day: invalid day-of-month '{$original}' for the current month");
        }

        return $resolved;
    }

    private static function formatDuration(string $startedAt, string $endedAt): string
    {
        $start = new DateTimeImmutable($startedAt);
        $end = new DateTimeImmutable($endedAt);
        $minutes = max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins === 0 ? "{$hours}h" : "{$hours}h {$mins}m";
    }
}
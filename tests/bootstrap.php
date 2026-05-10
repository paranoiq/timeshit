<?php declare(strict_types=1);

use Timeshit\App;
use Timeshit\Config;
use Timeshit\Configurator;
use Timeshit\Local\InMemoryRecordStore;
use Timeshit\Local\Record;
use Timeshit\Util\BufferedIo;
use Timeshit\Util\FixedClock;
use Timeshit\Youtrack\StubIssueDataProvider;
use Timeshit\Youtrack\StubTypeProvider;
use Timeshit\Youtrack\WorkItemType;

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Prague');

/**
 * Builds an `App` wired with in-memory + stub collaborators. Returns the app
 * plus the live store/clock/io references so tests can assert on state and
 * advance time / queue inputs between commands.
 *
 * @param list<Record> $records initial records in the store
 * @param list<string> $typeNames YouTrack work-item type names available to the stub provider
 * @return array{App, InMemoryRecordStore, FixedClock, BufferedIo}
 */
function newApp(
    string $now = '2026-05-09 10:00',
    array $records = [],
    array $typeNames = ['Implementation', 'Test / Review', 'Documentation', 'Out of office', 'Analyses / Design', 'Communication, Meetings, ...'],
): array {
    $config = new Config(
        youtrackBaseUrl: 'https://example.youtrack.cloud',
        youtrackToken: 'fake-token',
        timezone: 'Europe/Prague',
        defaultIssuePrefix: 'SW-',
        allowedTypes: ['Implementation', 'Test / Review', 'Documentation', 'Out of office', 'Analyses / Design', 'Communication, Meetings, ...'],
        defaultTrackType: 'Implementation',
        defaultDayType: 'Out of office',
        interruptionTypes: ['Communication, Meetings, ...', 'Test / Review'],
    );
    $store = new InMemoryRecordStore($records);
    $clock = new FixedClock($now);
    $io = new BufferedIo();
    $types = [];
    foreach ($typeNames as $i => $name) {
        $types[] = new WorkItemType(id: (string) $i, name: $name);
    }
    $configurator = new Configurator(__DIR__ . '/_fixtures', $io);
    $app = new App(
        config: $config,
        store: $store,
        types: new StubTypeProvider($types),
        issueData: new StubIssueDataProvider(),
        clock: $clock,
        io: $io,
        configurator: $configurator,
    );

    return [$app, $store, $clock, $io];
}
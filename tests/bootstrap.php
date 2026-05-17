<?php declare(strict_types=1);

use Timeshit\App;
use Timeshit\Config;
use Timeshit\Configurator;
use Timeshit\CustomCommand;
use Timeshit\Local\InMemoryRecordStore;
use Timeshit\Local\Record;
use Timeshit\LocalServer;
use Timeshit\Util\BufferedIo;
use Timeshit\Util\FixedClock;
use Timeshit\Youtrack\StubIssueDataProvider;
use Timeshit\Youtrack\StubTypeProvider;
use Timeshit\Youtrack\StubWorkItemPusher;
use Timeshit\Youtrack\WorkItemType;

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Prague');

/**
 * Builds an `App` wired with in-memory + stub collaborators. Returns the app
 * plus the live store/clock/io/pusher references so tests can assert on
 * state and advance time / queue inputs between commands.
 *
 * @param list<Record> $records initial records in the store
 * @param list<string> $typeNames YouTrack work-item type names available to the stub provider
 * @return array{App, InMemoryRecordStore, FixedClock, BufferedIo, StubWorkItemPusher}
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
        typeAliases: [],
        defaultTrackType: 'Implementation',
        defaultDayType: 'Out of office',
        defaultDayIssue: 'SW-5070',
        interruptionTypes: ['Communication, Meetings, ...', 'Test / Review'],
        customCommands: [
            new CustomCommand(name: 'analyse',   parent: 'track',     type: 'Analyses / Design',            issue: ''),
            new CustomCommand(name: 'implement', parent: 'track',     type: 'Implementation',               issue: ''),
            new CustomCommand(name: 'review',    parent: 'track',     type: 'Test / Review',                issue: ''),
            new CustomCommand(name: 'meeting',   parent: 'interrupt', type: 'Communication, Meetings, ...', issue: 'SW-4002'),
            new CustomCommand(name: 'mail',      parent: 'interrupt', type: 'Communication, Meetings, ...', issue: 'SW-4002'),
            new CustomCommand(name: 'standup',   parent: 'interrupt', type: 'Communication, Meetings, ...', issue: 'SW-4002', note: 'daily standup'),
            new CustomCommand(name: 'lunchput',  parent: 'put',       type: 'Implementation',               issue: 'SW-9999', span: '1h'),
            new CustomCommand(name: 'sickday',   parent: 'days',      type: 'Out of office',                issue: 'SW-5070', day: 'today'),
            new CustomCommand(name: 'vacation',  parent: 'days',      type: 'Out of office'),
            new CustomCommand(name: 'lunch',     parent: 'pause',     note: 'lunch break'),
            new CustomCommand(name: 'review!',   parent: 'switch',    type: 'Test / Review'),
            new CustomCommand(name: 'tea',       parent: 'skip',      span: '5m'),
            new CustomCommand(name: 'wrap',      parent: 'end'),
        ],
        commandAliases: [
            'continue' => 'resume',
            'design'   => 'analyse',
            'test'     => 'review',
        ],
        editor: 'true',
        closedIssueRetentionDays: 90,
        port: 1985,
    );
    $store = new InMemoryRecordStore($records);
    $clock = new FixedClock($now);
    $io = new BufferedIo();
    $types = [];
    foreach ($typeNames as $i => $name) {
        $types[] = new WorkItemType(id: (string) $i, name: $name);
    }
    $configurator = new Configurator(__DIR__ . '/_fixtures', $io);
    $pusher = new StubWorkItemPusher();
    $app = new App(
        config: $config,
        store: $store,
        types: new StubTypeProvider($types),
        issueData: new StubIssueDataProvider(),
        clock: $clock,
        io: $io,
        configurator: $configurator,
        pusher: $pusher,
        server: new LocalServer(__DIR__ . '/_fixtures/server.pid', __DIR__ . '/_fixtures/server.php', 1985, $io),
    );

    return [$app, $store, $clock, $io, $pusher];
}
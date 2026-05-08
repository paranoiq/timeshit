<?php declare(strict_types=1);

require __DIR__ . '/src/Ansi.php';
require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Format.php';
require __DIR__ . '/src/Local/Record.php';
require __DIR__ . '/src/Local/Store.php';
require __DIR__ . '/src/Youtrack/Issue.php';
require __DIR__ . '/src/Youtrack/WorkItem.php';
require __DIR__ . '/src/Youtrack/WorkItemType.php';
require __DIR__ . '/src/Youtrack/IssueCache.php';
require __DIR__ . '/src/Youtrack/WorkItemCache.php';
require __DIR__ . '/src/Youtrack/WorkItemTypeCache.php';
require __DIR__ . '/src/Youtrack/YoutrackClient.php';
require __DIR__ . '/src/View/IssuesView.php';
require __DIR__ . '/src/View/WorkView.php';
require __DIR__ . '/src/View/RecordsView.php';
require __DIR__ . '/src/App.php';

use Timeshit\App;

exit((new App(__DIR__))->run($argv));

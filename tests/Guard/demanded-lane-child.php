<?php

/**
 * Child process of DemandedLaneTest: runs the real CreatesApplication lifecycle of one test class, in a clean process
 * (no PHPUnit error handler), and prints what survived the refusal as one JSON line. Never opens a connection itself.
 *
 *   php tests/Guard/demanded-lane-child.php <class>
 */

use Illuminate\Foundation\Application;
use Tests\Support\TestTargetRefused;

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../Support/TestTargetGuard.php';
require_once __DIR__.'/../Support/GuardedDatabase.php';
require_once __DIR__.'/../CreatesApplication.php';
require_once __DIR__.'/../../packages/Mothership/DAM/tests/Feature/AutomationManualWriteRacePostgresTest.php';
require_once __DIR__.'/../../packages/Mothership/DAM/tests/Feature/MappingChunkCountsRacePostgresTest.php';

$class = $argv[1] ?? '';
$test = (new ReflectionClass($class))->newInstanceWithoutConstructor();

try {
    $test->createApplication();
    echo json_encode(['refused' => false]), "\n";
} catch (TestTargetRefused) {
    $app = Application::getInstance();
    echo json_encode(['refused' => true, 'providers' => array_keys($app->getLoadedProviders()), 'db_resolved' => $app->resolved('db'), 'connector_bound' => $app->bound('db.connector.pgsql')]), "\n";
} catch (Throwable $e) {
    echo json_encode(['refused' => false, 'error' => $e::class.': '.$e->getMessage()]), "\n";
}

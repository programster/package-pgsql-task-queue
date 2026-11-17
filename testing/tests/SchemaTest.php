<?php

/*
 * Tests of installing the tables (TaskQueueSchema::migrate()), which main.php has already done once, into the
 * task_queue_test schema.
 */

use Programster\PgsqlTaskQueue\SystemClock;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\TaskQueueSchema;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;

/** @var SmokeTest $smoke */


$tablesInSchema = function () : void {
    $query = <<<'SQL'
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'task_queue_test'
        ORDER BY table_name
        SQL;

    $tables = pg_fetch_all_columns(TestDatabase::get()->query($query));
    SmokeTest::assertSame(['batch', 'migrations', 'task', 'task_dependency'], $tables, "the schema's tables");
    $version = TestDatabase::get()->query('SELECT version FROM task_queue_test.migrations');
    SmokeTest::assertSame('1', pg_fetch_result($version, 0, 0), "the queue's migration version");
};

$smoke->run("schema: the tables and their own migration version go in the queue's schema", $tablesInSchema);

$migrateAgain = function () : void {
    $database = TestDatabase::get();
    $database->query("SET search_path TO public");
    TaskQueueSchema::migrate($database, TestDatabase::SCHEMA);
    $searchPath = pg_fetch_result($database->query('SHOW search_path'), 0, 0);
    SmokeTest::assertSame('public', $searchPath, "the search path, put back after migrating");
};

$smoke->run("schema: migrating again changes nothing, and puts the search path back", $migrateAgain);

$badSchemas = function () : void {
    foreach (['', 'Task_Queue', '1queue', 'task-queue', 'a"b', str_repeat('a', 64)] as $badSchema)
    {
        $migrate = function () use ($badSchema) : void {
            TaskQueueSchema::migrate(TestDatabase::get(), $badSchema);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $migrate, "schema");

        $createQueue = function () use ($badSchema) : void {
            new TaskQueue(TestDatabase::get(), new SystemClock(), $badSchema);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $createQueue, "schema");
    }
};

$smoke->run("schema: a schema name that isn't a lowercase identifier is rejected", $badSchemas);

<?php

/*
 * Tests of creating the tables (TaskQueue::createTables() and getCreateTablesSql()), which main.php has already done
 * once, in the task_queue_test schema.
 */

use Programster\PgsqlTaskQueue\TaskQueue;
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
    SmokeTest::assertSame(['task', 'task_dependency'], $tables, "the schema's tables");
};

$smoke->run("schema: the tables go in the queue's own schema", $tablesInSchema);

$createAgain = function () : void {
    TaskQueue::createTables(TestDatabase::get(), TestDatabase::SCHEMA);
    $sql = TaskQueue::getCreateTablesSql('other_queue');
    assert(str_contains($sql, 'CREATE TABLE IF NOT EXISTS "other_queue".task ('), "The SQL should use the schema.");
    assert(!str_contains($sql, 'task_queue_test'), "The SQL for another schema shouldn't mention this one.");
};

$smoke->run("schema: creating the tables again changes nothing, and the SQL uses the schema given", $createAgain);

$badSchemas = function () : void {
    foreach (['', 'Task_Queue', '1queue', 'task-queue', 'a"b', str_repeat('a', 64)] as $badSchema)
    {
        $getSql = function () use ($badSchema) : void {
            TaskQueue::getCreateTablesSql($badSchema);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $getSql, "schema");

        $createQueue = function () use ($badSchema) : void {
            new TaskQueue(TestDatabase::get(), null, $badSchema);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $createQueue, "schema");
    }
};

$smoke->run("schema: a schema name that isn't a lowercase identifier is rejected", $badSchemas);

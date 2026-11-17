<?php

/*
 * The throwaway database the tests use, given by the TEST_DB_HOST, TEST_DB_PORT (default 5432), TEST_DB_NAME,
 * TEST_DB_USER and TEST_DB_PASSWORD environment variables. The tests only ever touch the task_queue_test schema (and
 * the public.test_run table), so they can't harm a real queue, even in the same database.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


use Programster\PgsqlLib\PgSqlConnection;


final readonly class TestDatabase
{
    public const SCHEMA = 'task_queue_test';


    /**
     * Connect to the test database, with a connection of its own.
     * @return PgSqlConnection
     */
    public static function connect() : PgSqlConnection
    {
        $host = getenv('TEST_DB_HOST');
        $name = getenv('TEST_DB_NAME');

        if ($host === false || $host === '' || $name === false || $name === '')
        {
            throw new \RuntimeException("Set TEST_DB_HOST, TEST_DB_NAME, TEST_DB_USER and TEST_DB_PASSWORD.");
        }

        return PgSqlConnection::create(
            $host,
            $name,
            (string)getenv('TEST_DB_USER'),
            (string)getenv('TEST_DB_PASSWORD'),
            forceNew: true,
            port: (int)(getenv('TEST_DB_PORT') ?: 5432)
        );
    }


    /**
     * Get the shared connection the tests in this process use.
     * @return PgSqlConnection
     */
    public static function get() : PgSqlConnection
    {
        /** @var PgSqlConnection|null $connection */
        static $connection = null;

        if ($connection === null)
        {
            $connection = self::connect();
        }

        return $connection;
    }


    /**
     * Empty the queue's tables and the test_run table, so a test starts from nothing.
     */
    public static function reset() : void
    {
        $schema = self::SCHEMA;
        self::get()->query("TRUNCATE {$schema}.task_dependency, {$schema}.task, {$schema}.batch RESTART IDENTITY");
        self::get()->query("TRUNCATE public.test_run");
    }
}

<?php

/*
 * Installs and upgrades the queue's tables, in a schema of their own, with their own migration version (using
 * programster/pgsql-migrations), so they never clash with the application's tables or migrations.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlMigrations\MigrationManager;
use Programster\PgsqlTaskQueue\Internal\TaskStore;


final readonly class TaskQueueSchema
{
    public const DEFAULT_SCHEMA = 'task_queue';


    /**
     * Create the schema and its tables, or bring them up to date, e.g. when the application runs its own migrations.
     * Run it once in a process: the migration classes are loaded from their files the first time, so a second call in
     * the same process (e.g. for another schema) can't load them again.
     *
     * @param PgSqlConnection $connection - the database.
     *
     * @param string $schema - the schema to put the tables in. Defaults to "task_queue".
     *
     * @return void
     *
     * @throws \InvalidArgumentException - if the schema isn't 1 to 63 lowercase letters, digits and underscores.
     * @throws \Exception - if a migration fails.
     */
    public static function migrate(PgSqlConnection $connection, string $schema = self::DEFAULT_SCHEMA) : void
    {
        TaskStore::assertValidSchema($schema);
        $previousSearchPath = (string)pg_fetch_result($connection->query('SHOW search_path'), 0, 0);

        // The migrations create their tables without a schema, so they go in the first schema on the search path.
        $connection->query("SET search_path TO {$connection->escapeIdentifier($schema)}");

        try
        {
            $folder = __DIR__ . '/migrations';
            $manager = new MigrationManager($folder, $connection->getResource(), 'migrations', $schema);
            $manager->migrate();
        }
        finally
        {
            $connection->query("SELECT set_config('search_path', $1, false)", [$previousSearchPath]);
        }
    }
}

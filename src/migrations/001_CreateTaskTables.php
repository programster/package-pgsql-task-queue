<?php

/*
 * Creates the queue's tables. TaskQueueSchema::migrate() runs it with the queue's schema first on the search path, so
 * the tables go in that schema.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Migrations;


use PgSql\Connection;
use Programster\PgsqlMigrations\MigrationInterface;


final class Migration001CreateTaskTables implements MigrationInterface
{
    public function up(Connection $connectionResource) : void
    {
        $query = <<<'SQL'
            CREATE TABLE batch (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL
            );

            CREATE TABLE task (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                batch_id BIGINT NULL REFERENCES batch (id) ON DELETE CASCADE,
                queue TEXT NOT NULL,
                type TEXT NOT NULL,
                payload JSONB NOT NULL,
                name TEXT NULL,
                priority INT NOT NULL,
                status TEXT NOT NULL
                    CHECK (status IN ('blocked', 'waiting', 'running', 'succeeded', 'dead', 'skipped')),
                num_unmet_dependencies INT NOT NULL CHECK (num_unmet_dependencies >= 0),
                attempts INT NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                max_attempts INT NOT NULL CHECK (max_attempts >= 1),
                retry_delays_seconds INT[] NOT NULL,
                on_interruption TEXT NOT NULL CHECK (on_interruption IN ('retry', 'fail')),
                lease_seconds INT NOT NULL CHECK (lease_seconds >= 1),
                deduplication_key TEXT NULL,
                expected_duration_ms INT NULL,
                available_at TIMESTAMPTZ NOT NULL,
                lease_expires_at TIMESTAMPTZ NULL,
                worker TEXT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                started_at TIMESTAMPTZ NULL,
                finished_at TIMESTAMPTZ NULL,
                duration_ms INT NULL,
                result JSONB NULL,
                output TEXT NULL,
                error TEXT NULL,
                skipped_because_id BIGINT NULL REFERENCES task (id) ON DELETE SET NULL
            );

            CREATE TABLE task_dependency (
                task_id BIGINT NOT NULL REFERENCES task (id) ON DELETE CASCADE,
                depends_on_id BIGINT NOT NULL REFERENCES task (id) ON DELETE CASCADE,
                PRIMARY KEY (task_id, depends_on_id)
            );

            -- Finding the tasks that wait for one that has just finished.
            CREATE INDEX task_dependency_depends_on_idx ON task_dependency (depends_on_id);

            -- Claiming the next task, which is the queue's busiest query.
            CREATE INDEX task_claim_idx ON task (queue, priority DESC, expected_duration_ms DESC NULLS LAST, id)
                WHERE status = 'waiting';

            -- Finding running tasks whose lease has run out.
            CREATE INDEX task_lease_idx ON task (lease_expires_at) WHERE status = 'running';

            -- A deduplication key is unique among a queue's unfinished tasks.
            CREATE UNIQUE INDEX task_deduplication_idx ON task (queue, deduplication_key)
                WHERE deduplication_key IS NOT NULL AND status IN ('blocked', 'waiting', 'running');

            -- How long the last task with the same name took, to claim the longest first.
            CREATE INDEX task_previous_duration_idx ON task (queue, name, finished_at DESC)
                WHERE status = 'succeeded' AND name IS NOT NULL;

            CREATE INDEX task_batch_idx ON task (batch_id, status);
            CREATE INDEX task_skipped_because_idx ON task (skipped_because_id) WHERE skipped_because_id IS NOT NULL;
            CREATE INDEX task_finished_idx ON task (finished_at) WHERE status IN ('succeeded', 'dead', 'skipped');
            CREATE INDEX task_page_idx ON task (queue, status, id DESC);
            SQL;

        if (pg_query($connectionResource, $query) === false)
        {
            $error = pg_last_error($connectionResource);
            throw new \RuntimeException("Couldn't create the task queue's tables: {$error}");
        }
    }


    public function down(Connection $connectionResource) : void
    {
        if (pg_query($connectionResource, 'DROP TABLE task_dependency, task, batch') === false)
        {
            throw new \RuntimeException("Couldn't drop the task queue's tables: " . pg_last_error($connectionResource));
        }
    }
}

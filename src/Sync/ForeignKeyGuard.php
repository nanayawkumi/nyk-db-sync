<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ForeignKeyGuard
{
    public function withoutChecks(string $connection, callable $callback): mixed
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->withStatements($db, 'SET FOREIGN_KEY_CHECKS=0', 'SET FOREIGN_KEY_CHECKS=1', $callback);
        }

        if ($driver === 'sqlite') {
            return $this->withStatements($db, 'PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys = ON', $callback);
        }

        if ($driver !== 'pgsql') {
            return $callback();
        }

        return $this->withoutPostgresConstraints($db, $callback);
    }

    private function withStatements(Connection $db, string $disable, string $restore, callable $callback): mixed
    {
        try {
            $db->unprepared($disable);
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not disable foreign key checks', [
                'connection' => $db->getName(),
                'error' => $exception->getMessage(),
            ]);

            return $callback();
        }

        try {
            return $callback();
        } finally {
            try {
                $db->unprepared($restore);
            } catch (Throwable $exception) {
                Log::warning('[nyk-db-sync] Could not restore foreign key checks', [
                    'connection' => $db->getName(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function withoutPostgresConstraints(Connection $db, callable $callback): mixed
    {
        $constraints = $this->postgresForeignKeys($db);
        $dropped = [];

        Log::info('[nyk-db-sync] Foreign keys will be removed for the copy and restored afterward', [
            'connection' => $db->getName(),
            'constraints' => count($constraints),
        ]);

        try {
            foreach ($constraints as $constraint) {
                $db->unprepared(sprintf(
                    'alter table %s drop constraint %s',
                    $this->quote($constraint['table']),
                    $this->quote($constraint['name']),
                ));
                $dropped[] = $constraint;
            }
        } catch (Throwable $exception) {
            $this->restorePostgresForeignKeys($db, $dropped);

            throw new RuntimeException(
                'Could not disable foreign keys on ['.$db->getName().']. The database user must own the tables. '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $callbackException = null;
        $result = null;

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $callbackException = $exception;
        }

        $restoreException = $this->restorePostgresForeignKeys($db, $dropped);

        if ($callbackException !== null) {
            throw $callbackException;
        }

        if ($restoreException !== null) {
            throw $restoreException;
        }

        return $result;
    }

    /**
     * @return list<array{table: string, name: string, definition: string}>
     */
    private function postgresForeignKeys(Connection $db): array
    {
        $rows = $db->select(
            'select tc.relname as table_name, c.conname as constraint_name, pg_get_constraintdef(c.oid) as definition '
            .'from pg_constraint c '
            .'join pg_class tc on c.conrelid = tc.oid '
            .'join pg_namespace tn on tn.oid = tc.relnamespace '
            ."where c.contype = 'f' and tn.nspname = current_schema()",
        );

        $constraints = [];

        foreach ($rows as $row) {
            $table = (string) ($row->table_name ?? '');
            $name = (string) ($row->constraint_name ?? '');
            $definition = (string) ($row->definition ?? '');

            if ($table === '' || $name === '' || $definition === '') {
                continue;
            }

            $constraints[] = [
                'table' => $table,
                'name' => $name,
                'definition' => $definition,
            ];
        }

        return $constraints;
    }

    /**
     * @param  list<array{table: string, name: string, definition: string}>  $constraints
     */
    private function restorePostgresForeignKeys(Connection $db, array $constraints): ?Throwable
    {
        $failure = null;

        foreach ($constraints as $constraint) {
            $sql = sprintf(
                'alter table %s add constraint %s %s',
                $this->quote($constraint['table']),
                $this->quote($constraint['name']),
                $constraint['definition'],
            );

            try {
                $db->unprepared($sql);
            } catch (Throwable $exception) {
                if ($this->isAlreadyDefined($exception)) {
                    continue;
                }

                if (! $this->isForeignKeyViolation($exception)) {
                    Log::error('[nyk-db-sync] Could not restore a foreign key after the copy', [
                        'connection' => $db->getName(),
                        'table' => $constraint['table'],
                        'constraint' => $constraint['name'],
                        'error' => $exception->getMessage(),
                    ]);
                    $failure ??= $exception;

                    continue;
                }

                try {
                    $db->unprepared($sql.' not valid');
                    Log::warning('[nyk-db-sync] Foreign key was restored without checking existing rows because a parent is missing', [
                        'connection' => $db->getName(),
                        'table' => $constraint['table'],
                        'constraint' => $constraint['name'],
                        'error' => $exception->getMessage(),
                    ]);
                } catch (Throwable $retry) {
                    Log::error('[nyk-db-sync] Could not restore a foreign key after the copy', [
                        'connection' => $db->getName(),
                        'table' => $constraint['table'],
                        'constraint' => $constraint['name'],
                        'error' => $retry->getMessage(),
                    ]);
                    $failure ??= $retry;
                }
            }
        }

        return $failure;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function isAlreadyDefined(Throwable $exception): bool
    {
        $current = $exception;

        while ($current !== null) {
            if ((string) $current->getCode() === '42710' || str_contains($current->getMessage(), 'already exists')) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    private function isForeignKeyViolation(Throwable $exception): bool
    {
        $current = $exception;

        while ($current !== null) {
            if ((string) $current->getCode() === '23503' || str_contains($current->getMessage(), 'violates foreign key constraint')) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }
}

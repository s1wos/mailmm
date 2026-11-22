<?php

namespace App\Database;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\SQLiteConnection;
use RuntimeException;

class RollbackMigrator extends Migrator
{
    protected function rollbackMigrations(array $migrations, $paths, array $options): array
    {
        if ($options['pretend'] ?? false) {
            return parent::rollbackMigrations($migrations, $paths, $options);
        }

        $connection = $this->resolveConnection(null);
        $checkForeignKeys = $connection instanceof SQLiteConnection && $connection->scalar('PRAGMA foreign_keys');

        if ($checkForeignKeys) {
            if ($connection->transactionLevel() > 0) {
                throw new RuntimeException('Run SQLite migration rollback outside an existing transaction.');
            }
            $connection->getSchemaBuilder()->disableForeignKeyConstraints();
        }

        try {
            return $connection->transaction(function () use ($connection, $checkForeignKeys, $migrations, $paths, $options) {
                $this->checkRollbackCompatibility($migrations, $paths);
                $rolledBack = parent::rollbackMigrations($migrations, $paths, $options);
                if ($checkForeignKeys && $connection->selectOne('PRAGMA foreign_key_check')) {
                    throw new RuntimeException('Rollback would leave invalid foreign keys.');
                }

                return $rolledBack;
            });
        } finally {
            if ($checkForeignKeys) {
                $connection->getSchemaBuilder()->enableForeignKeyConstraints();
            }
        }
    }

    private function checkRollbackCompatibility(array $migrations, $paths): void
    {
        $files = $this->getMigrationFiles($paths);
        foreach ($migrations as $record) {
            if (! isset($files[$record->migration])) {
                continue;
            }
            $migration = $this->resolvePath($files[$record->migration]);
            if (method_exists($migration, 'assertCanRollBack')) {
                $this->runMethod($this->resolveConnection($migration->getConnection()), $migration, 'assertCanRollBack');
            }
        }
    }
}

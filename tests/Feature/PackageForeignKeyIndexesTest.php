<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every foreign key on a table the package migrates has an index that starts
 * with its columns. PostgreSQL and SQLite do not create one for a foreign key
 * (MySQL and MariaDB do), and without it a delete on the referenced table
 * scans the whole child table.
 */

// Every case runs on PostgreSQL, MySQL and MariaDB in CI.
pest()->group('engines');

const PACKAGE_TABLES = ['user_groups', 'user_group_user', 'user_group_role', 'principals'];

beforeEach(function (): void {
    foreach ([...array_reverse(PACKAGE_TABLES), 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $migrations = dirname(__DIR__, 2).'/database/migrations';
    (require "{$migrations}/groups/2026_11_22_120000_create_user_groups_tables.php")->up();
    (require "{$migrations}/principals/2026_11_24_100000_create_principals_table.php")->up();
});

afterEach(function (): void {
    foreach ([...array_reverse(PACKAGE_TABLES), 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('indexes every foreign key of the package tables', function (): void {
    $unindexedForeignKeys = [];

    foreach (PACKAGE_TABLES as $table) {
        $indexColumnLists = array_column(Schema::getIndexes($table), 'columns');

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $isIndexed = array_filter(
                $indexColumnLists,
                static fn (array $indexColumns): bool => array_slice($indexColumns, 0, count($foreignKey['columns'])) === $foreignKey['columns'],
            ) !== [];

            if (! $isIndexed) {
                $unindexedForeignKeys[] = $table.'.'.implode(',', $foreignKey['columns']);
            }
        }
    }

    expect($unindexedForeignKeys)->toBe([]);
});

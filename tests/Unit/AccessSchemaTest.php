<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Qiick\Access\Laravel\AccessSchema;

/*
 * The CHECK path of AccessSchema for PostgreSQL, MySQL and MariaDB, run with
 * a recording connection so the SQL is pinned on every CI cell; the feature
 * tests prove the same rules against the real engines.
 */

/**
 * @param  list<string>  $existingConstraints
 * @return array{Connection, list<string>}
 */
function recordingConnection(string $driver, array $existingConstraints = [], array $tables = ['user_groups', 'principals']): array
{
    $statements = new ArrayObject;
    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->andReturnUsing(static fn (string $table): bool => in_array($table, $tables, true));

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getDatabaseName')->andReturn('product_db');
    $connection->shouldReceive('statement')->andReturnUsing(static function (string $sql) use ($statements): bool {
        $statements[] = $sql;

        return true;
    });
    $connection->shouldReceive('table')->andReturnUsing(static function (string $table) use ($existingConstraints, $statements): Builder {
        $query = Mockery::mock(Builder::class);
        $wheres = [];
        $query->shouldReceive('where')->andReturnUsing(static function (string $column, string $value) use (&$wheres, $query): Builder {
            $wheres[$column] = $value;

            return $query;
        });
        $query->shouldReceive('exists')->andReturnUsing(static function () use (&$wheres, $existingConstraints, $statements, $table): bool {
            $statements[] = "lookup {$table} ".json_encode($wheres);

            return in_array($wheres['conname'] ?? $wheres['CONSTRAINT_NAME'] ?? '', $existingConstraints, true);
        });

        return $query;
    });

    return [$connection, $statements];
}

afterEach(function (): void {
    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
});

it('adds missing CHECK constraints with the engine boolean and the principal rule', function (): void {
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service', 'robot']);
    [$connection, $statements] = recordingConnection('pgsql');

    AccessSchema::ensureConstraints($connection);

    expect($statements->getArrayCopy())->toBe([
        'lookup pg_constraint {"conname":"user_groups_instance_org_xor"}',
        'ALTER TABLE user_groups ADD CONSTRAINT user_groups_instance_org_xor CHECK (is_instance = false OR organisation_id IS NULL)',
        'lookup pg_constraint {"conname":"principals_organisation_rule"}',
        "ALTER TABLE principals ADD CONSTRAINT principals_organisation_rule CHECK ((type IN ('instance_service', 'robot') AND organisation_id IS NULL AND user_id IS NULL) OR (type NOT IN ('instance_service', 'robot') AND organisation_id IS NOT NULL))",
    ]);
});

it('looks constraints up in the current MySQL / MariaDB schema and skips existing ones', function (string $driver): void {
    config()->set('access.principals.organisation_required', true);
    [$connection, $statements] = recordingConnection($driver, ['principals_organisation_rule']);

    AccessSchema::ensureConstraints($connection);

    expect($statements->getArrayCopy())->toBe([
        'lookup information_schema.TABLE_CONSTRAINTS {"CONSTRAINT_SCHEMA":"product_db","TABLE_NAME":"user_groups","CONSTRAINT_NAME":"user_groups_instance_org_xor"}',
        'ALTER TABLE user_groups ADD CONSTRAINT user_groups_instance_org_xor CHECK (is_instance = 0 OR organisation_id IS NULL)',
        'lookup information_schema.TABLE_CONSTRAINTS {"CONSTRAINT_SCHEMA":"product_db","TABLE_NAME":"principals","CONSTRAINT_NAME":"principals_organisation_rule"}',
    ]);
})->with(['mysql', 'mariadb']);

it('touches only the tables that exist, and principals only when organisations are required', function (): void {
    [$connection, $statements] = recordingConnection('pgsql', ['user_groups_instance_org_xor'], ['principals']);

    AccessSchema::ensureConstraints($connection);

    expect($statements->getArrayCopy())->toBe([]);
});

it('requires an organisation on every principal when no instance type is declared', function (): void {
    config()->set('access.principals.organisation_required', true);
    [$connection, $statements] = recordingConnection('pgsql', ['user_groups_instance_org_xor']);

    AccessSchema::ensureConstraints($connection);

    expect($statements->getArrayCopy()[2])
        ->toBe('ALTER TABLE principals ADD CONSTRAINT principals_organisation_rule CHECK (organisation_id IS NOT NULL)');
});

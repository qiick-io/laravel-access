<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Qiick\Access\Testing\AssertsSpatieTeamSchema;

it('is a no-op when Spatie teams are off', function (): void {
    config()->set('permission.teams', false);

    $asserter = new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        app('db')->connection(),
    );

    $asserter->assertWhenTeamsEnabled();
    expect(true)->toBeTrue();
});

it('fails when teams are on and team_foreign_key is not organisation_id (A3)', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'team_id');

    $asserter = new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        app('db')->connection(),
    );

    expect(fn () => $asserter->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'organisation_id');
});

it('fails when a team-scoped Spatie table is missing', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', []);

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->once()->with('roles')->andReturn(false);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'Spatie table [roles] is missing');
});

it('fails when organisation_id column is missing on a team-scoped table', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->once()->with('roles')->andReturn(true);
    $schema->shouldReceive('hasColumn')->once()->with('roles', 'organisation_id')->andReturn(false);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'must have an organisation_id column');
});

it('uses default table names when permission.table_names is empty', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', []);

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->with('roles')->andReturn(true);
    $schema->shouldReceive('hasColumn')->with('roles', 'organisation_id')->andReturn(true);
    $schema->shouldReceive('hasTable')->with('model_has_roles')->andReturn(true);
    $schema->shouldReceive('hasColumn')->with('model_has_roles', 'organisation_id')->andReturn(true);
    $schema->shouldReceive('hasTable')->with('model_has_permissions')->andReturn(false);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn('sqlite');

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'model_has_permissions');
});

it('uses configured table names instead of defaults', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'custom_roles',
        'model_has_roles' => 'custom_model_has_roles',
        'model_has_permissions' => 'custom_model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->once()->with('custom_roles')->andReturn(false);
    $schema->shouldReceive('hasTable')->with('roles')->never();

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'custom_roles');
});

it('fails when a configured table name is not a non-empty string', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => '',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'permission.table_names.roles');
});

it('fails when a configured table name is not a string', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 12,
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'permission.table_names.roles');
});

it('falls back to defaults when permission.table_names is not an array', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', 'not-an-array');

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->once()->with('roles')->andReturn(false);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'Spatie table [roles] is missing');
});

it('asserts organisation_id is uuid on PostgreSQL via information_schema', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        $schema->shouldReceive('hasTable')->with($table)->andReturn(true);
        $schema->shouldReceive('hasColumn')->with($table, 'organisation_id')->andReturn(true);
    }

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')
        ->times(3)
        ->withArgs(function (string $sql, array $bindings): bool {
            return $sql === 'select data_type from information_schema.columns'
                .' where table_schema = current_schema()'
                .' and table_name = ? and column_name = ?'
                && count($bindings) === 2
                && $bindings[1] === 'organisation_id';
        })
        ->andReturn((object) ['data_type' => 'uuid']);

    (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled();

    expect(true)->toBeTrue();
});

it('fails on PostgreSQL when organisation_id data_type is not uuid', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->with('roles')->andReturn(true);
    $schema->shouldReceive('hasColumn')->with('roles', 'organisation_id')->andReturn(true);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')
        ->once()
        ->withArgs(function (string $sql, array $bindings): bool {
            return str_contains($sql, 'table_schema = current_schema()')
                && str_contains($sql, 'information_schema.columns')
                && $bindings === ['roles', 'organisation_id'];
        })
        ->andReturn((object) ['data_type' => 'bigint']);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'must be uuid');
});

it('reads data_type from an array row on PostgreSQL', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        $schema->shouldReceive('hasTable')->with($table)->andReturn(true);
        $schema->shouldReceive('hasColumn')->with($table, 'organisation_id')->andReturn(true);
    }

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')->andReturn(['data_type' => 'uuid']);

    (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled();

    expect(true)->toBeTrue();
});

it('fails on PostgreSQL when information_schema returns no usable data_type', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $schema = Mockery::mock(SchemaBuilder::class);
    $schema->shouldReceive('hasTable')->with('roles')->andReturn(true);
    $schema->shouldReceive('hasColumn')->with('roles', 'organisation_id')->andReturn(true);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('selectOne')->andReturn(null);

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'must be uuid');
});

it('passes when teams are on with organisation_id and uuid columns on pgsql-compatible stubs', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $connection = app('db')->connection();
    $schema = $connection->getSchemaBuilder();

    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, function (Blueprint $blueprint): void {
            $blueprint->uuid('organisation_id')->nullable();
        });
    }

    $asserter = new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    );

    $asserter->assertWhenTeamsEnabled();
    expect(true)->toBeTrue();

    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        $schema->dropIfExists($table);
    }
});

it('fails on PostgreSQL when organisation_id is not uuid (A3)', function (): void {
    if (app('db')->connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only uuid type check');
    }

    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    $connection = app('db')->connection();
    $schema = $connection->getSchemaBuilder();

    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('organisation_id')->nullable();
        });
    }

    expect(fn () => (new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        $connection,
    ))->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'must be uuid');

    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        $schema->dropIfExists($table);
    }
})->group('engines');

<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Testing\AssertsRolesSchema;

/*
 * A4: the product-owned columns on Spatie's roles table (docs/reference-schema.md).
 */

// Every case runs on PostgreSQL, MySQL and MariaDB in CI.
pest()->group('engines');

/**
 * A roles table with the reference columns; $changes alters it per case.
 *
 * @param  Closure(Blueprint): void|null  $changes
 */
function createRolesTable(array $omittedColumns = [], ?Closure $changes = null): void
{
    Schema::dropIfExists('roles');
    Schema::create('roles', function (Blueprint $table) use ($omittedColumns, $changes): void {
        $table->id();
        $columns = [
            'public_id' => static fn () => $table->uuid('public_id')->unique(),
            'is_system' => static fn () => $table->boolean('is_system')->default(false),
            'is_assignable' => static fn () => $table->boolean('is_assignable')->default(true),
            'description' => static fn () => $table->text('description')->nullable(),
            'metadata' => static fn () => $table->json('metadata')->nullable(),
        ];

        $table->string('name');
        $table->string('guard_name');

        foreach ($columns as $column => $addColumn) {
            if (! in_array($column, $omittedColumns, true)) {
                $addColumn();
            }
        }

        $table->timestamps();

        if ($changes !== null) {
            $changes($table);
        }
    });
}

function assertRolesSchema(): void
{
    (new AssertsRolesSchema(DB::connection()))->assertShape();
}

afterEach(function (): void {
    Schema::dropIfExists('roles');
});

it('accepts the reference roles columns, and a product without the optional ones', function (array $omittedColumns): void {
    createRolesTable($omittedColumns);

    assertRolesSchema();

    expect(DB::table('roles')->count())->toBe(0);
})->with([
    'every column' => [[]],
    'only public_id (excubia)' => [['is_system', 'is_assignable', 'description', 'metadata']],
]);

it('requires a non-null, unique public_id (P3)', function (Closure $build, string $failure): void {
    $build();

    expect(fn () => assertRolesSchema())->toThrow(RuntimeException::class, $failure);
})->with([
    'missing' => [fn () => createRolesTable(['public_id']), 'A4: expected column [roles.public_id]'],
    'nullable' => [fn () => createRolesTable(['public_id'], static fn (Blueprint $table) => $table->uuid('public_id')->nullable()->unique()), 'A4: column [roles.public_id] must not be nullable'],
    'not unique' => [fn () => createRolesTable(['public_id'], static fn (Blueprint $table) => $table->uuid('public_id')->index()), 'A4: expected a unique index on [roles.public_id]'],
]);

it('checks the shape of the optional columns where a product has them', function (Closure $build, string $failure): void {
    $build();

    expect(fn () => assertRolesSchema())->toThrow(RuntimeException::class, $failure);
})->with([
    'is_system nullable' => [fn () => createRolesTable(['is_system'], static fn (Blueprint $table) => $table->boolean('is_system')->nullable()->default(false)), 'A4: column [roles.is_system] must not be nullable'],
    'is_system defaults to true' => [fn () => createRolesTable(['is_system'], static fn (Blueprint $table) => $table->boolean('is_system')->default(true)), 'A4: [roles.is_system] must default to false'],
    'is_assignable nullable' => [fn () => createRolesTable(['is_assignable'], static fn (Blueprint $table) => $table->boolean('is_assignable')->nullable()->default(true)), 'A4: column [roles.is_assignable] must not be nullable'],
    'is_assignable defaults to false' => [fn () => createRolesTable(['is_assignable'], static fn (Blueprint $table) => $table->boolean('is_assignable')->default(false)), 'A4: [roles.is_assignable] must default to true'],
    'description required' => [fn () => createRolesTable(['description'], static fn (Blueprint $table) => $table->text('description')), 'A4: column [roles.description] must be nullable'],
    'metadata required' => [fn () => createRolesTable(['metadata'], static fn (Blueprint $table) => $table->json('metadata')), 'A4: column [roles.metadata] must be nullable'],
]);

it('reads the roles table name from the Spatie config', function (): void {
    config()->set('permission.table_names.roles', 'access_roles');
    Schema::dropIfExists('access_roles');
    Schema::create('access_roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->uuid('public_id')->unique();
    });

    assertRolesSchema();
    Schema::drop('access_roles');

    // An empty or invalid setting falls back to Spatie's default name.
    foreach (['', 42] as $invalidTableName) {
        config()->set('permission.table_names.roles', $invalidTableName);

        expect(fn () => assertRolesSchema())->toThrow(RuntimeException::class, 'A4: expected table [roles]');
    }

    config()->set('permission.table_names.roles', 'roles');
});

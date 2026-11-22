<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Testing\AssertsGroupTablesSchema;

/**
 * Runs the package create_user_groups_tables migration on the configured engine.
 */
beforeEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
        $table->timestamps();
    });
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('users');
});

it('creates the package group tables with expires_at public_id and metadata', function (): void {
    $rolesColumnsBefore = collect(app('db')->connection()->getSchemaBuilder()->getColumns('roles'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_120000_create_user_groups_tables.php';
    $migration->up();

    expect(Schema::hasTable('user_groups'))->toBeTrue()
        ->and(Schema::hasColumns('user_groups', ['public_id', 'name', 'metadata', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('user_group_user', ['user_id', 'user_group_id', 'expires_at']))->toBeTrue()
        ->and(Schema::hasColumns('user_group_role', ['user_group_id', 'role_id']))->toBeTrue();

    $rolesColumnsAfter = collect(app('db')->connection()->getSchemaBuilder()->getColumns('roles'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($rolesColumnsAfter)->toBe($rolesColumnsBefore);

    (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape();

    $connection = app('db')->connection();
    $connection->table('users')->insert(['id' => 1, 'email' => 'a@example.com']);
    $connection->table('roles')->insert(['id' => 9, 'name' => 'reader', 'guard_name' => 'web']);
    $connection->table('user_groups')->insert([
        'public_id' => (string) Str::uuid(),
        'name' => 'ops',
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $groupId = (int) $connection->table('user_groups')->value('id');
    $connection->table('user_group_user')->insert([
        'user_group_id' => $groupId,
        'user_id' => 1,
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('user_group_role')->insert([
        'user_group_id' => $groupId,
        'role_id' => 9,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($connection->table('user_group_role')->count())->toBe(1);
})->group('engines');

it('points the membership user_id foreign key at access.users_table when set', function (): void {
    Schema::dropIfExists('accounts');
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });

    config()->set('access.users_table', 'accounts');

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_120000_create_user_groups_tables.php';
    $migration->up();

    $connection = app('db')->connection();
    $connection->table('accounts')->insert(['id' => 1]);
    $connection->table('user_groups')->insert([
        'public_id' => (string) Str::uuid(),
        'name' => 'finance',
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $groupId = (int) $connection->table('user_groups')->value('id');

    $connection->table('user_group_user')->insert([
        'user_group_id' => $groupId,
        'user_id' => 1,
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($connection->table('user_group_user')->count())->toBe(1);

    // Inserting a user_id that exists only on users (not accounts) must fail the FK.
    $connection->table('users')->insert(['id' => 99, 'email' => 'orphan@example.com']);

    expect(fn () => $connection->table('user_group_user')->insert([
        'user_group_id' => $groupId,
        'user_id' => 99,
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('accounts');
    config()->set('access.users_table', null);
})->group('engines');

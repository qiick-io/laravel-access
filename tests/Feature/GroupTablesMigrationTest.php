<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_120000_create_user_groups_tables.php';
    $migration->up();

    expect(Schema::hasTable('user_groups'))->toBeTrue()
        ->and(Schema::hasColumns('user_groups', ['public_id', 'name', 'metadata', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('user_group_user', ['user_id', 'user_group_id', 'expires_at']))->toBeTrue()
        ->and(Schema::hasColumns('user_group_role', ['user_group_id', 'role_id']))->toBeTrue();

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

it('honours access.table_names overrides when creating the package group tables', function (): void {
    config()->set('access.table_names', [
        'groups' => 'custom_groups',
        'group_user' => 'custom_group_user',
        'group_role' => 'custom_group_role',
    ]);

    Schema::dropIfExists('custom_group_role');
    Schema::dropIfExists('custom_group_user');
    Schema::dropIfExists('custom_groups');

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_120000_create_user_groups_tables.php';
    $migration->up();

    expect(Schema::hasTable('custom_groups'))->toBeTrue()
        ->and(Schema::hasTable('custom_group_user'))->toBeTrue()
        ->and(Schema::hasTable('custom_group_role'))->toBeTrue()
        ->and(Schema::hasTable('user_groups'))->toBeFalse();

    $migration->down();
    Schema::dropIfExists('custom_group_role');
    Schema::dropIfExists('custom_group_user');
    Schema::dropIfExists('custom_groups');
})->group('engines');

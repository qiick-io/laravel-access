<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Laravel\EloquentGroupRoleSource;
use Qiick\Access\Tests\Support\FakeAuthenticatable;

beforeEach(function (): void {
    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->softDeletes();
    });
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('user_group_id');
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_group_id');
        $table->unsignedBigInteger('role_id');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
});

it('binds EloquentGroupRoleSource as the default GroupRoleSource', function (): void {
    expect(app(GroupRoleSource::class))->toBeInstanceOf(EloquentGroupRoleSource::class);
});

it('returns role ids from active groups the user belongs to', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert(['id' => 1, 'name' => 'ops', 'deleted_at' => null]);
    $connection->table('user_group_user')->insert(['user_id' => 10, 'user_group_id' => 1]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 3],
        ['user_group_id' => 1, 'role_id' => 2],
    ]);

    $source = app(GroupRoleSource::class);
    $roleIds = $source->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([2, 3]);
});

it('excludes soft-deleted groups so they grant nothing (C7)', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        ['id' => 1, 'name' => 'live', 'deleted_at' => null],
        ['id' => 2, 'name' => 'trashed', 'deleted_at' => now()],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 10, 'user_group_id' => 1],
        ['user_id' => 10, 'user_group_id' => 2],
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 5],
        ['user_group_id' => 2, 'role_id' => 99],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([5])
        ->and($roleIds)->not->toContain(99);
});

it('returns an empty list when the user has no group memberships', function (): void {
    expect(app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(1)))->toBe([]);
});

it('falls back to fleet defaults when group config entries are missing or empty', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert(['id' => 1, 'name' => 'ops', 'deleted_at' => null]);
    $connection->table('user_group_user')->insert(['user_id' => 10, 'user_group_id' => 1]);
    $connection->table('user_group_role')->insert(['user_group_id' => 1, 'role_id' => 4]);

    $source = new EloquentGroupRoleSource($connection, [
        'table' => '',
        'user_pivot' => null,
        'role_pivot' => 1,
        'user_foreign_key' => '',
        'group_foreign_key' => false,
        'role_foreign_key' => '',
    ]);

    expect($source->roleIdsFor(new FakeAuthenticatable(10)))->toBe([4]);
});

it('uses overridden group table names from config', function (): void {
    Schema::create('custom_groups', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });
    Schema::create('custom_group_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('member_id');
        $table->unsignedBigInteger('group_id');
    });
    Schema::create('custom_group_role', function (Blueprint $table): void {
        $table->unsignedBigInteger('group_id');
        $table->unsignedBigInteger('role_key');
    });

    $connection = app('db')->connection();
    $connection->table('custom_groups')->insert(['id' => 1, 'deleted_at' => null]);
    $connection->table('custom_group_user')->insert(['member_id' => 10, 'group_id' => 1]);
    $connection->table('custom_group_role')->insert(['group_id' => 1, 'role_key' => 8]);

    $source = new EloquentGroupRoleSource($connection, [
        'table' => 'custom_groups',
        'user_pivot' => 'custom_group_user',
        'role_pivot' => 'custom_group_role',
        'user_foreign_key' => 'member_id',
        'group_foreign_key' => 'group_id',
        'role_foreign_key' => 'role_key',
    ]);

    expect($source->roleIdsFor(new FakeAuthenticatable(10)))->toBe([8]);

    Schema::dropIfExists('custom_group_role');
    Schema::dropIfExists('custom_group_user');
    Schema::dropIfExists('custom_groups');
});

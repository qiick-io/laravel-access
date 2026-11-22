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
        $table->uuid('public_id');
        $table->string('name');
        $table->json('metadata')->nullable();
        $table->softDeletes();
    });
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('user_group_id');
        $table->dateTime('expires_at')->nullable();
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
    $connection->table('user_groups')->insert([
        'id' => 1,
        'public_id' => '11111111-1111-7111-8111-111111111111',
        'name' => 'ops',
        'deleted_at' => null,
    ]);
    $connection->table('user_group_user')->insert([
        'user_id' => 10,
        'user_group_id' => 1,
        'expires_at' => null,
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 3],
        ['user_group_id' => 1, 'role_id' => 2],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([2, 3]);
})->group('engines');

it('excludes soft-deleted groups so they grant nothing (C7)', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        [
            'id' => 1,
            'public_id' => '11111111-1111-7111-8111-111111111111',
            'name' => 'live',
            'deleted_at' => null,
        ],
        [
            'id' => 2,
            'public_id' => '22222222-2222-7222-8222-222222222222',
            'name' => 'trashed',
            'deleted_at' => now(),
        ],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 10, 'user_group_id' => 1, 'expires_at' => null],
        ['user_id' => 10, 'user_group_id' => 2, 'expires_at' => null],
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 5],
        ['user_group_id' => 2, 'role_id' => 99],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([5])
        ->and($roleIds)->not->toContain(99);
})->group('engines');

it('ignores memberships whose expires_at is in the past (A2 / AC15)', function (): void {
    $this->travelTo(now()->parse('2026-11-22 12:00:00'));

    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        [
            'id' => 1,
            'public_id' => '11111111-1111-7111-8111-111111111111',
            'name' => 'current',
            'deleted_at' => null,
        ],
        [
            'id' => 2,
            'public_id' => '22222222-2222-7222-8222-222222222222',
            'name' => 'expired',
            'deleted_at' => null,
        ],
        [
            'id' => 3,
            'public_id' => '33333333-3333-7333-8333-333333333333',
            'name' => 'future',
            'deleted_at' => null,
        ],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 10, 'user_group_id' => 1, 'expires_at' => null],
        ['user_id' => 10, 'user_group_id' => 2, 'expires_at' => '2026-11-22 11:59:59'],
        ['user_id' => 10, 'user_group_id' => 3, 'expires_at' => '2026-11-22 12:00:01'],
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 1],
        ['user_group_id' => 2, 'role_id' => 2],
        ['user_group_id' => 3, 'role_id' => 3],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([1, 3])
        ->and($roleIds)->not->toContain(2);
})->group('engines');

it('returns an empty list when the user has no group memberships', function (): void {
    expect(app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(1)))->toBe([]);
})->group('engines');

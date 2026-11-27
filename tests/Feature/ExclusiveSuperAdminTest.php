<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\ExclusiveSuperAdmin;
use Qiick\Access\ExclusiveSuperAdminException;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Decision 2026-11-26 and Excubia #474: super-admin stands alone on every write
 * path, not only behind the product's role form (Spatie CLI, OIDC role sync, API).
 */

beforeEach(function (): void {
    config()->set('permission.teams', false);
    config()->set('auth.providers.users.model', SpatieUser::class);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['super-admin', 'admin', 'reader'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web']);
    }

    groupRolesAre();
});

afterEach(function (): void {
    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function exclusiveUser(string ...$roles): SpatieUser
{
    $user = SpatieUser::query()->create(['email' => uniqid().'@example.com']);
    $user->roles()->attach(Role::query()->whereIn('name', $roles)->pluck('id'));

    return $user;
}

function directRoles(SpatieUser $user): array
{
    return $user->fresh()->getRoleNames()->sort()->values()->all();
}

function groupRolesAre(string ...$roles): void
{
    $ids = Role::query()->whereIn('name', $roles)->pluck('id')->all();
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn($ids);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn($ids);
    app()->instance(GroupRoleSource::class, $groupRoles);
    app()->forgetInstance(EffectivePermissionResolver::class);
}

it('refuses to add super-admin to a user who holds another role, and takes it back off', function (): void {
    $user = exclusiveUser('reader');

    expect(fn () => $user->assignRole('super-admin'))->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe(['reader']);
});

it('refuses to add another role to a super-admin, and takes it back off', function (): void {
    $user = exclusiveUser('super-admin');

    expect(fn () => $user->assignRole('admin'))->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe(['super-admin']);
});

it('keeps only the super-admin role of a mixed sync, as OIDC role mapping does', function (): void {
    $user = exclusiveUser('reader');

    expect(fn () => $user->syncRoles(['super-admin', 'reader']))->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe(['super-admin']);
});

it('leaves valid assignments alone', function (): void {
    $user = exclusiveUser();
    $user->assignRole('admin', 'reader');
    $user->syncRoles(['super-admin']);

    expect(directRoles($user))->toBe(['super-admin']);
});

it('applies to the Spatie assign-role command', function (): void {
    $user = exclusiveUser('super-admin');

    expect(fn () => Artisan::call('permission:assign-role', ['name' => 'reader', 'userId' => $user->id, 'guard' => 'web', 'userModelNamespace' => SpatieUser::class]))
        ->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe(['super-admin']);
})->skip(fn (): bool => ! array_key_exists('permission:assign-role', Artisan::all()), 'This Spatie version has no assign-role command.');

it('counts group roles: a super-admin through a group gets no direct role', function (): void {
    $user = exclusiveUser();
    groupRolesAre('super-admin');

    expect(fn () => $user->assignRole('reader'))->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe([]);
});

it('lets products check a user after a group write', function (): void {
    $user = exclusiveUser('reader');

    app(ExclusiveSuperAdmin::class)->assertHeldAlone($user);
    groupRolesAre('super-admin');

    expect(fn () => app(ExclusiveSuperAdmin::class)->assertHeldAlone($user))->toThrow(ExclusiveSuperAdminException::class);
});

it('ignores super-admin from a group that cannot mint it', function (): void {
    // An organisation group carrying the role name is not an instance super-admin (C30).
    $user = exclusiveUser('reader');
    $ids = Role::query()->where('name', 'super-admin')->pluck('id')->all();
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn($ids);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    app()->instance(GroupRoleSource::class, $groupRoles);
    app()->forgetInstance(EffectivePermissionResolver::class);

    app(ExclusiveSuperAdmin::class)->assertHeldAlone($user);
    $user->assignRole('admin');

    expect(directRoles($user))->toBe(['admin', 'reader']);
});

it('decides on role names case-insensitively', function (): void {
    $service = app(ExclusiveSuperAdmin::class);

    expect($service->breaks(['Super-Admin', 'reader']))->toBeTrue()
        ->and($service->breaks(['super-admin', 'SUPER-ADMIN']))->toBeFalse()
        ->and($service->breaks(['admin', 'reader']))->toBeFalse()
        ->and($service->breaks([]))->toBeFalse();
});

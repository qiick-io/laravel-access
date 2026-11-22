<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config()->set('permission.teams', false);
    config()->set('auth.providers.users.model', SpatieUser::class);

    foreach ([
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'permissions',
        'roles',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    // Package-root vendor path: Testbench base_path() points at its sandbox app.
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    foreach ([
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'permissions',
        'roles',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

it('merges direct and group role permissions for a Spatie user', function (): void {
    $show = Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    $edit = Permission::create(['name' => FakePermissionEnum::CanEditUsers->value, 'guard_name' => 'web']);

    $member = Role::create(['name' => 'member', 'guard_name' => 'web']);
    $member->givePermissionTo($show);

    $groupRole = Role::create(['name' => 'group-editor', 'guard_name' => 'web']);
    $groupRole->givePermissionTo($edit);

    $user = SpatieUser::query()->create(['email' => 'member@example.com']);
    $user->assignRole($member);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with(Mockery::type(SpatieUser::class))->andReturn([$groupRole->id]);

    // Scoped resolver is already built at boot with EloquentGroupRoleSource.
    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->instance(GroupRoleSource::class, $groupRoles);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->roleNamesFor($user))->toBe(['group-editor', 'member'])
        ->and($resolver->effectiveRoleIds($user))->toBe([(string) $member->id, (string) $groupRole->id])
        ->and($resolver->permissionsFor($user))->toBe([
            FakePermissionEnum::CanEditUsers->value,
            FakePermissionEnum::CanShowUsers->value,
        ])
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanEditUsers->value))->toBeTrue()
        ->and($resolver->isSuperAdmin($user))->toBeFalse();
});

it('treats a direct super-admin role as holding every vocabulary permission', function (): void {
    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $user = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $user->assignRole('super-admin');

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->instance(GroupRoleSource::class, $groupRoles);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($resolver->permissionsFor($user))->toBe([
            FakePermissionEnum::CanShowUsers->value,
            FakePermissionEnum::CanEditUsers->value,
        ])
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($resolver->hasPermission($user, 'not-a-permission'))->toBeFalse();
});

it('detects super-admin when the role arrives only through group membership', function (): void {
    $superAdmin = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $user = SpatieUser::query()->create(['email' => 'group-admin@example.com']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('roleIdsFor')->with(Mockery::type(SpatieUser::class))->andReturn([$superAdmin->id]);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->with(Mockery::type(SpatieUser::class))->andReturn([$superAdmin->id]);

    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->instance(GroupRoleSource::class, $groupRoles);

    $registrar = app(PermissionRegistrar::class);
    $registrar->teams = true;

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($registrar->teams)->toBeTrue()
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue();
});

it('loads only permissions attached to the given group role ids', function (): void {
    $show = Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    $edit = Permission::create(['name' => FakePermissionEnum::CanEditUsers->value, 'guard_name' => 'web']);

    $groupRole = Role::create(['name' => 'group-viewer', 'guard_name' => 'web']);
    $groupRole->givePermissionTo($show);

    $decoy = Role::create(['name' => 'decoy-editor', 'guard_name' => 'web']);
    $decoy->givePermissionTo($edit);

    $user = SpatieUser::query()->create(['email' => 'viewer@example.com']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([$groupRole->id]);

    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->instance(GroupRoleSource::class, $groupRoles);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->permissionsFor($user))->toBe([FakePermissionEnum::CanShowUsers->value])
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and($resolver->roleNamesFor($user))->toBe(['group-viewer']);
});

it('ignores the Spatie team filter when checking super-admin (C30)', function (): void {
    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $user = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $user->assignRole('super-admin');

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->instance(GroupRoleSource::class, $groupRoles);

    $registrar = app(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->setPermissionsTeamId('org-that-has-no-roles');

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($registrar->teams)->toBeTrue();
});

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Laravel\SpatieTeamContext;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * C30 reads super-admin with Spatie's team filter off. That read must never
 * leave an unfiltered `roles` relation on the user (organisation bleed into the
 * current team), nor trust a filtered relation loaded earlier (hiding an
 * instance super-admin). A team switch drops the resolver cache (C29).
 */

beforeEach(function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('auth.providers.users.model', SpatieUser::class);

    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
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

    $registrar = app(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->forgetCachedPermissions();

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

/**
 * A user whose only role is `org-editor` (granting can-show-users) in org-1,
 * reloaded while the current team is org-2 so no relation is loaded yet.
 */
function userWithOrganisationOneEditorRoleCheckedInOrganisationTwo(): SpatieUser
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-1');

    $showUsersPermission = Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    $organisationEditorRole = Role::create(['name' => 'org-editor', 'guard_name' => 'web', 'organisation_id' => 'org-1']);
    $organisationEditorRole->givePermissionTo($showUsersPermission);

    $user = SpatieUser::query()->create(['email' => 'editor@example.com']);
    $user->assignRole($organisationEditorRole);

    $registrar->setPermissionsTeamId('org-2');

    return SpatieUser::query()->findOrFail($user->id);
}

it('does not grant another organisation\'s permission after the super-admin check', function (): void {
    $user = userWithOrganisationOneEditorRoleCheckedInOrganisationTwo();
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($user))->toBeFalse()
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeFalse()
        ->and($resolver->roleNamesFor($user))->toBe([])
        ->and($user->getRoleNames()->all())->toBe([])
        ->and(app(PermissionRegistrar::class)->teams)->toBeTrue();
});

it('does not grant another organisation\'s permission on a fresh check', function (): void {
    $user = userWithOrganisationOneEditorRoleCheckedInOrganisationTwo();
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value, true))->toBeFalse();
});

it('does not leak another organisation\'s roles after the direct super-admin check', function (): void {
    $user = userWithOrganisationOneEditorRoleCheckedInOrganisationTwo();
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isDirectSuperAdmin($user))->toBeFalse()
        ->and($user->getRoleNames()->all())->toBe([])
        ->and($resolver->permissionsFor($user))->toBe([])
        ->and(app(PermissionRegistrar::class)->teams)->toBeTrue();
});

it('finds an instance super-admin even when a team-filtered roles relation is already loaded', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-1');
    $superAdminRole = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);

    $user = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $user->assignRole($superAdminRole);

    $registrar->setPermissionsTeamId('org-2');
    $checkedUser = SpatieUser::query()->findOrFail($user->id);
    expect($checkedUser->getRoleNames()->all())->toBe([]);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isDirectSuperAdmin($checkedUser))->toBeTrue()
        ->and($resolver->isSuperAdmin($checkedUser))->toBeTrue()
        ->and($checkedUser->getRoleNames()->all())->toBe([])
        ->and($registrar->teams)->toBeTrue();
});

it('drops the resolver cache on a team switch so a revoked permission is not served when the team returns (C29)', function (): void {
    // How a product that enables teams binds the adapter.
    $this->app->singleton(TeamContext::class, SpatieTeamContext::class);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    $user = userWithOrganisationOneEditorRoleCheckedInOrganisationTwo();
    $teamContext = app(TeamContext::class);
    $resolver = app(EffectivePermissionResolver::class);

    $teamContext->switchTo('org-1');
    expect($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue();

    $teamContext->switchTo('org-2');
    // A revocation that fires no Spatie event, so only the switch can clear it.
    DB::table('role_has_permissions')->delete();
    $teamContext->switchTo('org-1');

    expect($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('ignores roles and permissions relations loaded under another team', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-1');

    $showUsersPermission = Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    $organisationEditorRole = Role::create(['name' => 'org-editor', 'guard_name' => 'web', 'organisation_id' => 'org-1']);

    $user = SpatieUser::query()->create(['email' => 'direct@example.com']);
    $user->givePermissionTo($showUsersPermission);
    $user->assignRole($organisationEditorRole);
    $user->load(['roles', 'permissions']);

    $registrar->setPermissionsTeamId('org-2');
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->permissionsFor($user))->toBe([])
        ->and($resolver->roleNamesFor($user))->toBe([]);
});

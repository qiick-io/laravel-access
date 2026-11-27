<?php

declare(strict_types=1);

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakeAuthenticatable;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;
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

    // No group tables here: role writes run the exclusive super-admin check,
    // which reads groups. Tests that need groups bind their own source.
    $noGroups = Mockery::mock(GroupRoleSource::class);
    $noGroups->shouldReceive('roleIdsFor', 'instanceRoleIdsFor')->andReturn([]);
    app()->instance(GroupRoleSource::class, $noGroups);
    app()->forgetInstance(EffectivePermissionResolver::class);
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

/**
 * Same table and Spatie identity (morph class) as SpatieUser, another PHP class.
 */
final class MorphSiblingUser extends SpatieUser
{
    protected string $guard_name = 'web';

    public function getMorphClass(): string
    {
        return SpatieUser::class;
    }
}

it('treats models sharing a morph class as one principal for cache clears and identity', function (): void {
    $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $editor->givePermissionTo(Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']));

    $user = SpatieUser::query()->create(['email' => 'sibling@example.com']);
    $user->assignRole($editor);

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    $resolver = app(EffectivePermissionResolver::class);
    $sibling = MorphSiblingUser::query()->findOrFail($user->id);

    expect($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($resolver->isSamePrincipal($user, $sibling))->toBeTrue()
        ->and($resolver->isSamePrincipal($user, new FakeAuthenticatable($user->id)))->toBeFalse();

    // The Spatie event on the sibling instance clears the shared entry. A
    // fresh instance: $user's own loaded relation is not the subject here.
    $sibling->removeRole($editor);

    expect($resolver->hasPermission(SpatieUser::query()->findOrFail($user->id), FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('detects super-admin through an instance group without teams', function (): void {
    $superAdminRole = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $user = SpatieUser::query()->create(['email' => 'group-admin@example.com']);

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([$superAdminRole->id]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([$superAdminRole->id]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    expect(app(EffectivePermissionResolver::class)->isSuperAdmin($user))->toBeTrue();
});

it('leaves the super-admin role\'s own permissions out of the assigned ceiling, direct or through a group', function (): void {
    $superAdminRole = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $superAdminRole->givePermissionTo(Permission::create(['name' => FakePermissionEnum::CanEditUsers->value, 'guard_name' => 'web']));
    $viewerRole = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->givePermissionTo(Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']));

    $directAdmin = SpatieUser::query()->create(['email' => 'direct-admin@example.com']);
    $directAdmin->assignRole($superAdminRole);
    $groupAdmin = SpatieUser::query()->create(['email' => 'group-admin@example.com']);

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturnUsing(
        static fn (SpatieUser $user): array => $user->is($groupAdmin) ? [$superAdminRole->id] : [],
    );
    $groupRoles->shouldReceive('roleIdsFor')->andReturnUsing(
        static fn (SpatieUser $user): array => $user->is($groupAdmin) ? [$superAdminRole->id, $viewerRole->id] : [],
    );
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($directAdmin))->toBeTrue()
        ->and($resolver->hasAssignedPermission($directAdmin, FakePermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and($resolver->isSuperAdmin($groupAdmin))->toBeTrue()
        ->and($resolver->hasAssignedPermission($groupAdmin, FakePermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and($resolver->hasAssignedPermission($groupAdmin, FakePermissionEnum::CanShowUsers->value))->toBeTrue();
});

it('compares the super-admin role name exactly, the same way for direct and group roles', function (): void {
    // A look-alike: an ordinary role. A case-insensitive SQL collation
    // (MySQL / MariaDB) must not mistake it for the super-admin role.
    $lookAlikeRole = Role::create(['name' => 'Super-Admin', 'guard_name' => 'web']);
    $lookAlikeRole->givePermissionTo(Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']));

    $directHolder = SpatieUser::query()->create(['email' => 'direct-look-alike@example.com']);
    $directHolder->assignRole($lookAlikeRole);
    $groupHolder = SpatieUser::query()->create(['email' => 'group-look-alike@example.com']);

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturnUsing(
        static fn (SpatieUser $user): array => $user->is($groupHolder) ? [$lookAlikeRole->id] : [],
    );
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($directHolder))->toBeFalse()
        ->and($resolver->hasAssignedPermission($directHolder, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($resolver->isSuperAdmin($groupHolder))->toBeFalse()
        ->and($resolver->hasAssignedPermission($groupHolder, FakePermissionEnum::CanShowUsers->value))->toBeTrue();
})->group('engines');

it('grants no group permissions when the Spatie permission or role class is not a model', function (string $permissionClass, string $roleClass): void {
    $user = new PermissionedFakeUser(16, [], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([Role::create(['name' => 'group-viewer', 'guard_name' => 'web'])->id]);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;
    $registrar->shouldReceive('getPermissionClass')->andReturn($permissionClass);
    $registrar->shouldReceive('getRoleClass')->andReturn($roleClass);

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        Mockery::mock(PermissionVocabulary::class),
        $teamContext,
        $registrar,
    );

    // Reaching the query with a non-model class would throw.
    expect($resolver->hasAssignedPermission($user, 'can-show-users'))->toBeTrue();
})->with([
    'permission class not a model' => [stdClass::class, Role::class],
    'role class not a model' => [Permission::class, stdClass::class],
]);

it('keeps principals of two databases apart, in identity and in the cache', function (): void {
    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $admin = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $admin->assignRole('super-admin');

    // A second real database with the same tables and a plain user 1.
    $tenantDatabase = tempnam(sys_get_temp_dir(), 'access-tenant-');
    config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $tenantDatabase, 'prefix' => '']);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'tenant');

    try {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
        });
        (require dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        DB::table('users')->insert(['id' => $admin->id, 'email' => 'tenant@example.com']);
    } finally {
        config()->set('database.default', $defaultConnection);
    }

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $resolver = app(EffectivePermissionResolver::class);

    $tenantUser = SpatieUser::on('tenant')->findOrFail($admin->id);

    expect($resolver->isSuperAdmin($admin))->toBeTrue()
        ->and($resolver->isSamePrincipal($admin, $tenantUser))->toBeFalse()
        ->and($resolver->isSamePrincipal($admin, SpatieUser::query()->findOrFail($admin->id)))->toBeTrue()
        // Same connection and id, another morph class: another principal.
        ->and($resolver->isSamePrincipal($admin, (new class extends SpatieUser {})->forceFill(['id' => $admin->id])))->toBeFalse()
        ->and($resolver->isSuperAdmin($tenantUser))->toBeFalse()
        ->and($resolver->permissionsFor($tenantUser))->toBe([]);

    DB::purge('tenant');
    unlink($tenantDatabase);
});

it('never joins a connection name and a morph class into an ambiguous principal key', function (): void {
    config()->set('database.connections.region|archive', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.connections.region', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $archivedUser = (new class extends Model implements Illuminate\Contracts\Auth\Authenticatable
    {
        use Authenticatable;

        protected $connection = 'region|archive';

        public function getMorphClass(): string
        {
            return 'user';
        }
    })->forceFill(['id' => 1]);
    $regionUser = (new class extends Model implements Illuminate\Contracts\Auth\Authenticatable
    {
        use Authenticatable;

        protected $connection = 'region';

        public function getMorphClass(): string
        {
            return 'archive|user';
        }
    })->forceFill(['id' => 1]);

    expect(app(EffectivePermissionResolver::class)->isSamePrincipal($archivedUser, $regionUser))->toBeFalse();
});

it('never lets a morph sibling class reuse the cached group grants of another class', function (): void {
    $superAdmin = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $viewer = Role::create(['name' => 'group-viewer', 'guard_name' => 'web']);
    $viewer->givePermissionTo(Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']));
    $user = SpatieUser::query()->create(['email' => 'member@example.com']);
    $sibling = MorphSiblingUser::query()->findOrFail($user->id);

    // EloquentGroupRoleSource admits exactly one class: only SpatieUser has groups.
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturnUsing(fn ($member): array => $member::class === SpatieUser::class ? [$superAdmin->id] : []);
    $groupRoles->shouldReceive('roleIdsFor')->andReturnUsing(fn ($member): array => $member::class === SpatieUser::class ? [$viewer->id] : []);
    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->instance(RoleCatalog::class, $roleCatalog);
    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($resolver->hasAssignedPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($resolver->isSuperAdmin($sibling))->toBeFalse()
        ->and($resolver->hasAssignedPermission($sibling, FakePermissionEnum::CanShowUsers->value))->toBeFalse()
        ->and($resolver->permissionsFor($sibling))->toBe([]);
});

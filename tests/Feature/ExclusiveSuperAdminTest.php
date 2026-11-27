<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\ListsAllGroupRoles;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Events\SuperAdminBypassWithheldEvent;
use Qiick\Access\ExclusiveSuperAdmin;
use Qiick\Access\ExclusiveSuperAdminException;
use Qiick\Access\Laravel\EloquentGroupRoleSource;
use Qiick\Access\Principals\Principal;
use Qiick\Access\SuperAdminGuard;
use Qiick\Access\Tests\Support\FakePermissionEnum;
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

/** Group roles: $instance from instance groups, $elsewhere from another organisation's group. */
function groupRolesAre(array $instance = [], array $elsewhere = []): void
{
    $ids = fn (array $names): array => Role::query()->whereIn('name', $names)->pluck('id')->all();
    $groupRoles = Mockery::mock(GroupRoleSource::class, ListsAllGroupRoles::class);
    $groupRoles->shouldReceive('roleIdsFor', 'instanceRoleIdsFor')->andReturn($ids($instance));
    $groupRoles->shouldReceive('allRoleIdsFor')->andReturn($ids([...$instance, ...$elsewhere]));
    app()->scoped(GroupRoleSource::class, fn (): GroupRoleSource => $groupRoles);
    app()->forgetScopedInstances();
}

function writeRoles(SpatieUser $user, Closure $write): void
{
    app(ExclusiveSuperAdmin::class)->write($user, $write);
}

it('rolls a refused sync back to the roles held before, without a caller transaction', function (): void {
    // Codex review of #74: Spatie detaches the old roles before it attaches.
    $user = exclusiveUser('reader');

    expect(fn () => writeRoles($user, fn () => $user->syncRoles(['super-admin', 'reader'])))->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe(['reader']);
});

it('refuses another role for a super-admin and keeps roles named again', function (): void {
    $user = exclusiveUser('super-admin');

    expect(fn () => writeRoles($user, fn () => $user->assignRole('super-admin', 'admin')))->toThrow(ExclusiveSuperAdminException::class)
        ->and(directRoles($user))->toBe(['super-admin']);
});

it('commits a valid write', function (): void {
    $user = exclusiveUser('reader');

    writeRoles($user, fn () => $user->syncRoles(['super-admin']));

    expect(directRoles($user))->toBe(['super-admin']);
});

it('counts the group roles of every organisation', function (): void {
    $user = exclusiveUser('super-admin');
    groupRolesAre(elsewhere: ['reader']);

    expect(fn () => app(ExclusiveSuperAdmin::class)->assertHeldAlone($user))->toThrow(ExclusiveSuperAdminException::class);
});

it('refuses a group write that gives a member super-admin next to direct roles', function (): void {
    $user = exclusiveUser('reader');
    // The group source as the product's group write inside write() leaves it.
    groupRolesAre(instance: ['super-admin']);

    expect(fn () => writeRoles($user, fn (): null => null))->toThrow(ExclusiveSuperAdminException::class);
});

it('withholds the bypass from a mixed holder that skipped write(), and reports it', function (): void {
    // permission:assign-role or a raw attach: fail-closed on read.
    Event::fake([SuperAdminBypassWithheldEvent::class]);
    $user = exclusiveUser('super-admin', 'reader');
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->isSuperAdmin($user))->toBeFalse()
        ->and($resolver->isDirectSuperAdmin($user))->toBeFalse()
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeFalse()
        ->and($resolver->holdsMixedSuperAdmin($user))->toBeTrue();
    Event::assertDispatched(SuperAdminBypassWithheldEvent::class, fn (SuperAdminBypassWithheldEvent $event): bool => $event->user->is($user));
});

it('withholds the bypass for an ordinary role in another team as well', function (): void {
    $user = exclusiveUser('super-admin');
    groupRolesAre(elsewhere: ['reader']);

    expect(app(EffectivePermissionResolver::class)->isSuperAdmin($user))->toBeFalse();
});

it('keeps the bypass of a super-admin who holds nothing else', function (): void {
    $user = exclusiveUser('super-admin');

    expect(app(EffectivePermissionResolver::class)->isSuperAdmin($user))->toBeTrue()
        ->and(app(EffectivePermissionResolver::class)->isDirectSuperAdmin($user))->toBeTrue();
});

it('decides on role names case-insensitively', function (): void {
    $service = app(ExclusiveSuperAdmin::class);

    expect($service->breaks(['Super-Admin', 'reader']))->toBeTrue()
        ->and($service->breaks(['super-admin', 'SUPER-ADMIN']))->toBeFalse()
        ->and($service->breaks(['admin', 'reader']))->toBeFalse()
        ->and($service->breaks([]))->toBeFalse();
});

it('names the user in the exception', function (): void {
    expect(ExclusiveSuperAdminException::forUser(exclusiveUser())->getMessage())->toContain('User [1]');
});

function bindActiveUsers(): void
{
    app()->instance(ActiveUsers::class, Mockery::mock(ActiveUsers::class));
    app()->forgetScopedInstances();
}

it('keeps a mixed holder out of an ordinary admin\'s reach', function (): void {
    // Codex re-check of #74: protection follows the assignment, not the bypass.
    bindActiveUsers();
    $mixed = exclusiveUser('super-admin', 'reader');
    $admin = exclusiveUser('admin');

    expect(app(SuperAdminGuard::class)->isProtectedFrom($admin, $mixed))->toBeTrue()
        ->and(app(SuperAdminGuard::class)->isProtectedFrom($admin, exclusiveUser('reader')))->toBeFalse();
});

it('refuses an ordinary admin who turns a mixed holder into an effective super-admin', function (): void {
    bindActiveUsers();
    $mixed = exclusiveUser('super-admin', 'reader');
    $admin = exclusiveUser('admin');

    expect(fn () => app(ExclusiveSuperAdmin::class)->write($mixed, fn () => $mixed->removeRole('reader'), $admin))
        ->toThrow(ExclusiveSuperAdminException::class, 'may not manage super-admins')
        ->and(directRoles($mixed))->toBe(['reader', 'super-admin']);
});

it('lets a super-admin clean up a mixed holder', function (): void {
    bindActiveUsers();
    $mixed = exclusiveUser('super-admin', 'reader');
    $superAdmin = exclusiveUser('super-admin');

    app(ExclusiveSuperAdmin::class)->write($mixed, fn () => $mixed->removeRole('reader'), $superAdmin);

    expect(directRoles($mixed))->toBe(['super-admin'])
        ->and(app(EffectivePermissionResolver::class)->isSuperAdmin($mixed))->toBeTrue();
});

it('drops a verdict read inside a write that throws', function (): void {
    $user = exclusiveUser('reader');

    expect(fn () => writeRoles($user, function () use ($user): never {
        $user->syncRoles(['super-admin']);
        expect(app(EffectivePermissionResolver::class)->isSuperAdmin($user))->toBeTrue();

        throw new RuntimeException('Later step failed.');
    }))->toThrow(RuntimeException::class, 'Later step failed.')
        ->and(directRoles($user))->toBe(['reader'])
        ->and(app(EffectivePermissionResolver::class)->isSuperAdmin($user))->toBeFalse();
});

it('refuses a write for a user row that does not exist', function (): void {
    $ghost = (new SpatieUser)->forceFill(['id' => 999]);
    $ghost->exists = true;

    expect(fn () => writeRoles($ghost, fn (): null => null))->toThrow(LogicException::class, 'every user row to exist');
});

it('rolls a shared group write back for every member when one member breaks the rule', function (): void {
    // Real group tables and the package's own group source.
    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id');
        $table->string('name');
        $table->uuid('organisation_id')->nullable();
        $table->boolean('is_instance')->default(false);
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
    app()->scoped(GroupRoleSource::class, fn (): GroupRoleSource => app()->build(EloquentGroupRoleSource::class));
    app()->forgetScopedInstances();
    expect(app(GroupRoleSource::class))->toBeInstanceOf(EloquentGroupRoleSource::class);

    $reader = exclusiveUser('reader');
    $plain = exclusiveUser();

    try {
        expect(fn () => app(ExclusiveSuperAdmin::class)->write([$plain, $reader], function () use ($reader, $plain): void {
            $groupId = DB::table('user_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'admins', 'is_instance' => true]);
            DB::table('user_group_role')->insert(['user_group_id' => $groupId, 'role_id' => Role::findByName('super-admin')->id]);
            DB::table('user_group_user')->insert([
                ['user_group_id' => $groupId, 'user_id' => $plain->id],
                ['user_group_id' => $groupId, 'user_id' => $reader->id],
            ]);
        }))->toThrow(ExclusiveSuperAdminException::class, "User [{$reader->id}]")
            ->and(DB::table('user_groups')->count())->toBe(0)
            ->and(DB::table('user_group_user')->count())->toBe(0);
    } finally {
        Schema::dropIfExists('user_group_role');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
});

it('names the user, or unknown, in both exceptions', function (): void {
    $named = new GenericUser(['id' => 'u-7']);

    expect(ExclusiveSuperAdminException::forUser($named)->getMessage())->toStartWith('User [u-7] would hold')
        ->and(ExclusiveSuperAdminException::promotionBy(new GenericUser(['id' => 12]))->getMessage())->toStartWith('User [12] would become')
        ->and(ExclusiveSuperAdminException::forUser(new GenericUser(['id' => null]))->getMessage())->toStartWith('User [unknown]');
});

it('locks the users in key order', function (): void {
    $first = exclusiveUser();
    $second = exclusiveUser();
    DB::enableQueryLog();

    app(ExclusiveSuperAdmin::class)->write([$second, $first], fn (): null => null);

    $lockedIds = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "users"'))
        ->map(fn (array $query): mixed => $query['bindings'][0])
        ->values()
        ->all();

    expect($lockedIds)->toBe([$first->id, $second->id]);
});

it('reads the effective super-admin state fresh before the write', function (): void {
    // A raw attach after a cached verdict: the user already is a super-admin.
    bindActiveUsers();
    $user = exclusiveUser();
    expect(app(EffectivePermissionResolver::class)->isSuperAdmin($user))->toBeFalse();
    $user->roles()->attach(Role::findByName('super-admin'));

    app(ExclusiveSuperAdmin::class)->write($user, fn (): null => null, exclusiveUser('admin'));

    expect(directRoles($user))->toBe(['super-admin']);
});

it('leaves no stale roles loaded on the user after a refused write', function (): void {
    $user = exclusiveUser('reader');

    try {
        writeRoles($user, function () use ($user): void {
            $user->syncRoles(['super-admin', 'reader']);
            $user->load('roles');
        });
    } catch (ExclusiveSuperAdminException) {
    }

    expect($user->relationLoaded('roles'))->toBeFalse();
});

it('refuses users on different connections in one write', function (): void {
    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:']);
    $elsewhere = exclusiveUser();
    $elsewhere->setConnection('other');

    expect(fn () => app(ExclusiveSuperAdmin::class)->write([exclusiveUser(), $elsewhere], fn (): null => null))
        ->toThrow(LogicException::class, 'all on one connection');
});

it('lists mixed holders once, without group tables, and nothing for a users model without roles', function (): void {
    $mixed = exclusiveUser('super-admin', 'reader');
    exclusiveUser('super-admin');
    exclusiveUser('reader');

    expect(app(ExclusiveSuperAdmin::class)->mixedHolderIds())->toBe([(string) $mixed->id]);

    config()->set('auth.providers.users.model', Principal::class);
    expect(app(ExclusiveSuperAdmin::class)->mixedHolderIds())->toBe([]);

    config()->set('auth.providers.users.model', stdClass::class);
    expect(app(ExclusiveSuperAdmin::class)->mixedHolderIds())->toBe([]);

    config()->set('auth.providers.users.model', null);
    expect(app(ExclusiveSuperAdmin::class)->mixedHolderIds())->toBe([]);
});

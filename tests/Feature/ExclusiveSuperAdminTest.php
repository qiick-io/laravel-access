<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\ListsAllGroupRoles;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Events\SuperAdminBypassWithheldEvent;
use Qiick\Access\ExclusiveSuperAdmin;
use Qiick\Access\ExclusiveSuperAdminException;
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

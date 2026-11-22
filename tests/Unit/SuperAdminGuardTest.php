<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\SuperAdminGuard;
use Qiick\Access\Tests\Support\FakeAuthenticatable;
use Qiick\Access\Tests\Support\PermissionedFakeUser;
use Spatie\Permission\PermissionRegistrar;

function activeUsersReturning(array $users): ActiveUsers
{
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('cursor')->andReturnUsing(static function () use ($users): Generator {
        foreach ($users as $user) {
            yield $user;
        }
    });

    $activeUsers = Mockery::mock(ActiveUsers::class);
    $activeUsers->shouldReceive('query')->andReturn($builder);

    return $activeUsers;
}

function resolverForGuardUsers(): EffectivePermissionResolver
{
    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('all')->andReturn([]);
    $vocabulary->shouldReceive('isPermission')->andReturn(false);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    return new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );
}

it('allows only a super-admin actor to manage super-admins', function (): void {
    $admin = new PermissionedFakeUser(1, ['super-admin']);
    $member = new PermissionedFakeUser(2, ['member']);
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([]), app('db'));

    expect($guard->canManageSuperAdmins($admin))->toBeTrue()
        ->and($guard->canManageSuperAdmins($member))->toBeFalse()
        ->and($guard->canManageSuperAdmins(null))->toBeFalse();
});

it('protects a super-admin target from a non-super-admin actor', function (): void {
    $admin = new PermissionedFakeUser(1, ['super-admin']);
    $member = new PermissionedFakeUser(2, ['member']);
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([]), app('db'));

    expect($guard->isProtectedFrom($member, $admin))->toBeTrue()
        ->and($guard->isProtectedFrom($admin, $admin))->toBeFalse()
        ->and($guard->isProtectedFrom(null, $admin))->toBeTrue();
});

it('rolls back and reports when a change would leave no active super-admin', function (): void {
    Schema::create('super_admin_guard_probe', function (Blueprint $table): void {
        $table->string('name')->primary();
    });

    $admin = new PermissionedFakeUser(1, ['super-admin']);
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([$admin]), app('db'));

    $wouldLeave = $guard->wouldLeaveNoActiveSuperAdmin(static function () use ($admin): void {
        DB::table('super_admin_guard_probe')->insert(['name' => 'demoted']);
        $admin->setRoleNames([]);
    });

    expect($wouldLeave)->toBeTrue()
        ->and(DB::table('super_admin_guard_probe')->count())->toBe(0);

    Schema::drop('super_admin_guard_probe');
});

it('commits when at least one active super-admin remains', function (): void {
    Schema::create('super_admin_guard_probe', function (Blueprint $table): void {
        $table->string('name')->primary();
    });

    $admin = new PermissionedFakeUser(1, ['super-admin']);
    $other = new PermissionedFakeUser(2, ['super-admin']);
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([$admin, $other]), app('db'));

    $wouldLeave = $guard->wouldLeaveNoActiveSuperAdmin(static function () use ($admin): void {
        DB::table('super_admin_guard_probe')->insert(['name' => 'kept']);
        $admin->setRoleNames([]);
    });

    expect($wouldLeave)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::table('super_admin_guard_probe')->count())->toBe(1);

    Schema::drop('super_admin_guard_probe');
});

it('does not refuse when there was never an active super-admin', function (): void {
    $member = new FakeAuthenticatable(3);
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([$member]), app('db'));

    $wouldLeave = $guard->wouldLeaveNoActiveSuperAdmin(static function (): void {});

    expect($wouldLeave)->toBeFalse();
});

it('re-throws after rolling back when the change fails', function (): void {
    Schema::create('super_admin_guard_probe', function (Blueprint $table): void {
        $table->string('name')->primary();
    });

    $admin = new PermissionedFakeUser(1, ['super-admin']);
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([$admin]), app('db'));

    expect(fn () => $guard->wouldLeaveNoActiveSuperAdmin(static function (): void {
        DB::table('super_admin_guard_probe')->insert(['name' => 'failed']);
        throw new RuntimeException('change failed');
    }))->toThrow(RuntimeException::class, 'change failed');

    expect(DB::table('super_admin_guard_probe')->count())->toBe(0);

    Schema::drop('super_admin_guard_probe');
});

it('skips non-authenticatable rows when counting active super-admins', function (): void {
    $guard = new SuperAdminGuard(resolverForGuardUsers(), activeUsersReturning([new stdClass]), app('db'));

    $wouldLeave = $guard->wouldLeaveNoActiveSuperAdmin(static function (): void {});

    expect($wouldLeave)->toBeFalse();
});

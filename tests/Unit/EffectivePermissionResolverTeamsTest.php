<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\ListsAllGroupRoles;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakeAuthenticatable;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Spatie\Permission\PermissionRegistrar;

/**
 * Authenticatable whose getRoleNames() respects Spatie's team filter flag,
 * simulating an instance-level super-admin hidden while teams are on.
 */
final class TeamFilteredSuperAdminUser extends FakeAuthenticatable
{
    public function getRoleNames(): array
    {
        $registrar = app(PermissionRegistrar::class);

        if ($registrar->teams === true) {
            return [];
        }

        return ['super-admin'];
    }

    public function roles(): object
    {
        return new class
        {
            public function get(mixed ...$arguments): array
            {
                return [];
            }
        };
    }

    public function getAllPermissions(): array
    {
        return [];
    }
}

/**
 * B2: with Spatie teams on, instance super-admin must get vocabulary permissions
 * from permissionsFor the same way isSuperAdmin / hasPermission grant them.
 */
it('agrees permissionsFor with isSuperAdmin for an instance super-admin under teams', function (): void {
    $admin = new TeamFilteredSuperAdminUser(1);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class, ListsAllGroupRoles::class);
    // Every group of the user: here the current-team and instance ones.
    $groupRoles->shouldReceive('allRoleIdsFor')->andReturnUsing(fn (Authenticatable $user): array => [...$groupRoles->roleIdsFor($user), ...$groupRoles->instanceRoleIdsFor($user)]);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with(Mockery::type(Authenticatable::class))->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('all')->andReturn([
        FakePermissionEnum::CanShowUsers->value,
        FakePermissionEnum::CanEditUsers->value,
    ]);
    $vocabulary->shouldReceive('isPermission')
        ->with(FakePermissionEnum::CanShowUsers->value)
        ->andReturn(true);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn('org-A');

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = true;
    app()->instance(PermissionRegistrar::class, $registrar);

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->isSuperAdmin($admin))->toBeTrue()
        ->and($resolver->hasPermission($admin, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($resolver->permissionsFor($admin))->toBe([
            FakePermissionEnum::CanShowUsers->value,
            FakePermissionEnum::CanEditUsers->value,
        ])
        ->and($resolver->roleNamesFor($admin))->toContain('super-admin')
        ->and($resolver->effectiveRoleIds($admin))->toBe([])
        ->and($registrar->teams)->toBeTrue();
});

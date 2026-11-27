<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakeAuthenticatable;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('reports super-admin from role names without requiring a current team', function (): void {
    $user = new PermissionedFakeUser(7, ['super-admin']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

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

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($resolver->hasPermission($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($registrar->teams)->toBeTrue();
});

it('caches permissions per user and team until forget', function (): void {
    $user = new PermissionedFakeUser(3, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(1);

    $resolver->forget($user);

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(2);
});

it('keys the cache by user type as well as identifier', function (): void {
    $webUser = new PermissionedFakeUser(42, [], ['can-show-users']);
    $otherGuardUser = new class(42) extends PermissionedFakeUser {};

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        Mockery::mock(PermissionVocabulary::class),
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($webUser, 'can-show-users'))->toBeTrue()
        ->and($resolver->hasPermission($otherGuardUser, 'can-show-users'))->toBeFalse();

    $resolver->forget($otherGuardUser);

    expect($resolver->hasPermission($webUser, 'can-show-users'))->toBeTrue()
        ->and($webUser->permissionCalls)->toBe(1);
});

it('caches the super-admin verdict until forget', function (): void {
    $user = new PermissionedFakeUser(11, ['super-admin']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor', 'roleIdsFor')->andReturn([]);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        Mockery::mock(PermissionVocabulary::class),
        Mockery::mock(TeamContext::class),
        $registrar,
    );

    expect($resolver->isSuperAdmin($user))->toBeTrue();

    $user->setRoleNames([]);

    expect($resolver->isSuperAdmin($user))->toBeTrue();

    $resolver->forget($user);

    expect($resolver->isSuperAdmin($user))->toBeFalse();
});

it('reports assigned permissions without the super-admin grant, cached unless fresh', function (): void {
    $superAdmin = new PermissionedFakeUser(9, ['super-admin'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('all')->andReturn(['can-show-users', 'can-edit-users']);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver($roleCatalog, $groupRoles, $vocabulary, $teamContext, $registrar);

    expect($resolver->permissionsFor($superAdmin))->toBe(['can-show-users', 'can-edit-users'])
        ->and($resolver->hasAssignedPermission($superAdmin, 'can-show-users'))->toBeTrue()
        ->and($resolver->hasAssignedPermission($superAdmin, 'can-edit-users'))->toBeFalse();

    $superAdmin->setPermissionNames([]);

    expect($resolver->hasAssignedPermission($superAdmin, 'can-show-users'))->toBeTrue()
        ->and($superAdmin->permissionCalls)->toBe(1)
        ->and($resolver->hasAssignedPermission($superAdmin, 'can-show-users', true))->toBeFalse();
});

it('fresh bypasses a warm cache entry', function (): void {
    $user = new PermissionedFakeUser(4, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($user, 'can-edit-users'))->toBeFalse();

    $user->setPermissionNames(['can-edit-users']);

    expect($resolver->hasPermission($user, 'can-edit-users'))->toBeFalse()
        ->and($resolver->hasPermission($user, 'can-edit-users', fresh: true))->toBeTrue();
});

it('exposes role names, role ids and permissions for a member', function (): void {
    $user = new PermissionedFakeUser(5, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->roleNamesFor($user))->toBe(['member'])
        ->and($resolver->permissionsFor($user))->toBe(['can-show-users'])
        ->and($resolver->isSuperAdmin($user))->toBeFalse()
        ->and($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($resolver->hasPermission($user, 'can-edit-users'))->toBeFalse();
});

it('flush clears every cached user and forget null delegates to flush', function (): void {
    $user = new PermissionedFakeUser(6, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(1);

    $resolver->forget(null);

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(2);

    $resolver->forget($user);

    expect($user->unsetRelations)->toBe(['roles', 'permissions', 'groups'])
        ->and($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(3);
});

it('returns empty roles and permissions for users without Spatie helpers', function (): void {
    $user = new FakeAuthenticatable(8);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('all')->never();

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->roleNamesFor($user))->toBe([])
        ->and($resolver->effectiveRoleIds($user))->toBe([])
        ->and($resolver->permissionsFor($user))->toBe([])
        ->and($resolver->isSuperAdmin($user))->toBeFalse()
        ->and($resolver->hasPermission($user, 'can-show-users'))->toBeFalse();
});

it('keys the cache by team id so a switchTo forces a fresh resolve', function (): void {
    $user = new PermissionedFakeUser(9, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn('org-A', 'org-B');

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(1);

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(2);
});

it('keys the cache by integer team ids and string user identifiers', function (): void {
    $user = new PermissionedFakeUser('user-uuid', ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(42, 43);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(1)
        ->and($resolver->hasPermission($user, 'can-show-users'))->toBeTrue()
        ->and($user->permissionCalls)->toBe(2);
});

it('filters empty role and permission names and exposes direct role ids', function (): void {
    $user = new PermissionedFakeUser(11, ['member', ''], ['can-show-users', '']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->roleNamesFor($user))->toBe(['member'])
        ->and($resolver->permissionsFor($user))->toBe(['can-show-users'])
        ->and($resolver->effectiveRoleIds($user))->toBe(['1']);
});

it('detects a direct super-admin while ignoring the team filter (P6)', function (): void {
    $direct = new PermissionedFakeUser(20, ['super-admin']);
    $groupOnly = new PermissionedFakeUser(21, []);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($direct)->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($groupOnly)->andReturn([99]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn('org-A');

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->shouldReceive('getRoleClass')->andReturn(Role::class);

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->isDirectSuperAdmin($direct))->toBeTrue()
        ->and($resolver->isDirectSuperAdmin($groupOnly))->toBeFalse()
        ->and($registrar->teams)->toBeTrue();
});

it('leaves registrar teams false when super-admin is checked without teams', function (): void {
    $user = new PermissionedFakeUser(12, ['super-admin']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('isPermission')->with('can-show-users')->andReturn(true);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($registrar->teams)->toBeFalse()
        ->and($resolver->hasPermission($user, 'can-show-users'))->toBeTrue();
});

it('forgets only the matching user prefix so user 1 does not clear user 10', function (): void {
    $userOne = new PermissionedFakeUser(1, ['member'], ['can-show-users']);
    $userTen = new PermissionedFakeUser(10, ['member'], ['can-edit-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->hasPermission($userOne, 'can-show-users'))->toBeTrue()
        ->and($resolver->hasPermission($userTen, 'can-edit-users'))->toBeTrue()
        ->and($userOne->permissionCalls)->toBe(1)
        ->and($userTen->permissionCalls)->toBe(1);

    $resolver->forget($userOne);

    expect($resolver->hasPermission($userOne, 'can-show-users'))->toBeTrue()
        ->and($resolver->hasPermission($userTen, 'can-edit-users'))->toBeTrue()
        ->and($userOne->permissionCalls)->toBe(2)
        ->and($userTen->permissionCalls)->toBe(1);
});

it('deduplicates mixed int and string role ids and sorts them', function (): void {
    $user = new PermissionedFakeUser(13, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    // Duplicate of the direct role id (1) as a string, plus string ids that need casting.
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn(['1', 'role-b', 'role-a', 'role-b']);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;
    $registrar->shouldReceive('getRoleClass')->andReturn(stdClass::class);
    $registrar->shouldReceive('getPermissionClass')->andReturn(stdClass::class);

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->effectiveRoleIds($user))->toBe(['1', 'role-a', 'role-b'])
        ->and($user->loadMissingCalls)->toBe([
            ['permissions', 'roles.permissions'],
        ]);
});

it('skips invalid role and permission entries without aborting the list', function (): void {
    $user = new class(15) extends FakeAuthenticatable
    {
        public function roles(): object
        {
            return new class
            {
                public function get(mixed ...$arguments): array
                {
                    return [
                        'not-an-object',
                        new class
                        {
                            public function getAttribute(string $key): int
                            {
                                return 7;
                            }
                        },
                        new stdClass,
                    ];
                }
            };
        }

        public function getRoleNames(): array
        {
            return ['member', 12, ''];
        }

        public function getAllPermissions(): array
        {
            return [
                'not-an-object',
                new class
                {
                    public function getAttribute(string $key): string
                    {
                        return 'can-show-users';
                    }
                },
                new stdClass,
            ];
        }
    };

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->with($user)->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->effectiveRoleIds($user))->toBe(['7'])
        ->and($resolver->roleNamesFor($user))->toBe(['member'])
        ->and($resolver->permissionsFor($user))->toBe(['can-show-users']);
});

it('returns empty role ids when roles() is not a gettable relation', function (): void {
    $user = new class(16) extends FakeAuthenticatable
    {
        public function roles(): string
        {
            return 'not-a-relation';
        }

        public function getRoleNames(): array
        {
            return [];
        }

        public function getAllPermissions(): array
        {
            return [];
        }
    };

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->effectiveRoleIds($user))->toBe([]);
});

it('returns empty role ids when roles() is an object without get()', function (): void {
    $user = new class(18) extends FakeAuthenticatable
    {
        public function roles(): object
        {
            return new class {};
        }

        public function getRoleNames(): array
        {
            return ['member'];
        }

        public function getAllPermissions(): array
        {
            return [];
        }
    };

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->effectiveRoleIds($user))->toBe([])
        ->and($resolver->roleNamesFor($user))->toBe(['member']);
});

it('deduplicates duplicate role names from the same user', function (): void {
    $user = new PermissionedFakeUser(17, ['member', 'member', 'editor'], ['can-show-users', 'can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->roleNamesFor($user))->toBe(['editor', 'member'])
        ->and($resolver->permissionsFor($user))->toBe(['can-show-users']);
});

it('falls back to unknown when the auth identifier is neither int nor string', function (): void {
    $user = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return ['nested' => true];
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    expect($resolver->roleNamesFor($user))->toBe([])
        ->and($resolver->permissionsFor($user))->toBe([]);
});

it('bounds an owner ceiling by assigned permissions, or by the vocabulary for a super-admin', function (): void {
    $superAdmin = new PermissionedFakeUser(11, ['super-admin'], ['can-purge-everything']);
    $member = new PermissionedFakeUser(12, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('all')->andReturn(['can-show-users', 'can-edit-users']);
    $vocabulary->shouldReceive('isPermission')->andReturnUsing(
        static fn (string $name): bool => in_array($name, ['can-show-users', 'can-edit-users'], true),
    );

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver($roleCatalog, $groupRoles, $vocabulary, $teamContext, $registrar);

    expect($resolver->isWithinOwnerCeiling($superAdmin, 'can-edit-users'))->toBeTrue()
        // Assigned to the super-admin but outside the vocabulary: still out.
        ->and($resolver->isWithinOwnerCeiling($superAdmin, 'can-purge-everything'))->toBeFalse()
        ->and($resolver->isWithinOwnerCeiling($member, 'can-show-users'))->toBeTrue()
        ->and($resolver->isWithinOwnerCeiling($member, 'can-edit-users'))->toBeFalse();

    // Cached until a fresh read: a demotion counts at once only with fresh.
    $superAdmin->setRoleNames([]);

    expect($resolver->isWithinOwnerCeiling($superAdmin, 'can-edit-users'))->toBeTrue()
        ->and($resolver->isWithinOwnerCeiling($superAdmin, 'can-edit-users', true))->toBeFalse();
});

<?php

declare(strict_types=1);

use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;
use Spatie\Permission\PermissionRegistrar;

it('reports super-admin from role names without requiring a current team', function (): void {
    $user = new PermissionedFakeUser(7, ['super-admin']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
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

it('fresh bypasses a warm cache entry', function (): void {
    $user = new PermissionedFakeUser(4, ['member'], ['can-show-users']);

    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
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

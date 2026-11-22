<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\GateBefore;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;
use Spatie\Permission\PermissionRegistrar;

function makeTestResolver(): EffectivePermissionResolver
{
    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    return new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        app(PermissionVocabulary::class),
        $teamContext,
        $registrar,
    );
}

it('abstains when the Gate user is null', function (): void {
    expect(app(GateBefore::class)(null, FakePermissionEnum::CanShowUsers->value))->toBeNull();
});

it('abstains unconditionally for API actors even when they are super-admin (AC2)', function (): void {
    $user = new PermissionedFakeUser(1, ['super-admin']);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, $user, 'cred-1'));

    $gateBefore = new GateBefore(
        app(ActorResolver::class),
        makeTestResolver(),
        app(PermissionVocabulary::class),
        app(Gate::class),
    );

    expect($gateBefore($user, FakePermissionEnum::CanShowUsers->value))->toBeNull()
        ->and($gateBefore($user, 'any-ability'))->toBeNull();
});

it('grants when a web actor is super-admin and no Policy argument is present', function (): void {
    $user = new PermissionedFakeUser(1, ['super-admin']);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $user));

    $gateBefore = new GateBefore(
        app(ActorResolver::class),
        makeTestResolver(),
        app(PermissionVocabulary::class),
        app(Gate::class),
    );

    expect($gateBefore($user, 'any-ability'))->toBeTrue();
});

it('abstains for Policy-backed abilities so super-admin cannot pre-empt (AC3)', function (): void {
    $user = new PermissionedFakeUser(1, ['super-admin']);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $user));

    $gate = Mockery::mock(Gate::class);
    $gate->shouldReceive('getPolicyFor')->once()->with('App\\Models\\Post')->andReturn(new stdClass);

    $gateBefore = new GateBefore(
        app(ActorResolver::class),
        makeTestResolver(),
        app(PermissionVocabulary::class),
        $gate,
    );

    expect($gateBefore($user, 'update', ['App\\Models\\Post']))->toBeNull();
});

it('grants a bare permission ability through the resolver for web actors', function (): void {
    $user = new PermissionedFakeUser(1, ['member'], [FakePermissionEnum::CanShowUsers->value]);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $user));

    $gateBefore = new GateBefore(
        app(ActorResolver::class),
        makeTestResolver(),
        app(PermissionVocabulary::class),
        app(Gate::class),
    );

    expect($gateBefore($user, FakePermissionEnum::CanShowUsers->value))->toBeTrue();
});

it('never returns false', function (): void {
    $user = new PermissionedFakeUser(1, ['member'], []);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $user));

    $gateBefore = new GateBefore(
        app(ActorResolver::class),
        makeTestResolver(),
        app(PermissionVocabulary::class),
        app(Gate::class),
    );

    expect($gateBefore($user, FakePermissionEnum::CanShowUsers->value))->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\GateBefore;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;

beforeEach(function (): void {
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetScopedInstances();
});

/**
 * B1: GateBefore must not keep a warm EffectivePermissionResolver across
 * request/job scopes in the same container (Octane / queue workers).
 */
it('does not leak resolver cache across consecutive request scopes after a demotion (AC11)', function (): void {
    $member = new PermissionedFakeUser(1, ['member'], [FakePermissionEnum::CanShowUsers->value]);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $member));

    $gateBefore = app(GateBefore::class);
    expect($gateBefore($member, FakePermissionEnum::CanShowUsers->value))->toBeTrue();

    // End of request: scoped instances forgotten; RequestHandled flushes the
    // container's resolver. GateBefore must not keep granting from a captured one.
    AccessContext::clear();
    app()->forgetScopedInstances();
    event(new RequestHandled(Request::create('/'), response('ok')));

    $member->setPermissionNames([]);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $member));

    expect(app(GateBefore::class)($member, FakePermissionEnum::CanShowUsers->value))->toBeNull()
        ->and(app(EffectivePermissionResolver::class)->hasPermission($member, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('clears AccessContext after RequestHandled once the app terminates (N2)', function (): void {
    $user = new PermissionedFakeUser(3, ['member']);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $user));
    expect(AccessContext::current())->not->toBeNull();

    event(new RequestHandled(Request::create('/'), response('ok')));

    // Nested handle / terminating callbacks still see the actor until terminate.
    expect(AccessContext::current())->not->toBeNull();

    app()->terminate();

    expect(AccessContext::current())->toBeNull();
});

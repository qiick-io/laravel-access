<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Testing\ProbeAbility;
use Qiick\Access\Tests\Support\PermissionedFakeUser;

/*
 * AC2 probe: an ability defined as false. Only GateBefore's super-admin grant
 * can make it pass, so it shows whether an actor got that grant.
 */

beforeEach(function (): void {
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetScopedInstances();
});

afterEach(function (): void {
    AccessContext::clear();
});

it('passes for a super-admin web session and fails behind every API actor kind (AC2)', function (): void {
    ProbeAbility::enable();
    $ability = ProbeAbility::register($this->app);
    $gate = app(Gate::class);
    $superAdmin = new PermissionedFakeUser(1, ['super-admin']);

    expect($ability)->toBe(ProbeAbility::NAME)
        ->and($gate->has($ability))->toBeTrue()
        ->and($gate->forUser($superAdmin)->inspect($ability)->allowed())->toBeTrue()
        ->and($gate->forUser(new PermissionedFakeUser(2, ['member']))->allows($ability))->toBeFalse();

    AccessContext::enter(new ActorContext(ActorKind::WebSession, $superAdmin));
    expect($gate->forUser($superAdmin)->allows($ability))->toBeTrue();

    foreach ([ActorKind::ApiKey, ActorKind::OAuthGrant, ActorKind::PublicApi] as $apiKind) {
        AccessContext::enter(new ActorContext($apiKind, $superAdmin, 'credential-uuid'));

        expect($gate->forUser($superAdmin)->allows($ability))->toBeFalse();
    }
});

it('refuses to register outside a unit-test run', function (): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('runningUnitTests')->andReturn(false);

    expect(fn () => ProbeAbility::register($application))
        ->toThrow(LogicException::class, 'test-only');

    expect(app(Gate::class)->has(ProbeAbility::NAME))->toBeFalse();
});

it('refuses to register in a unit-test run without the explicit opt-in', function (): void {
    expect(fn () => ProbeAbility::register($this->app))
        ->toThrow(LogicException::class, 'ProbeAbility::enable()');

    ProbeAbility::enable();
    config()->set('access.testing.probe_ability', 'yes');

    expect(fn () => ProbeAbility::register($this->app))
        ->toThrow(LogicException::class, 'ProbeAbility::enable()')
        ->and(app(Gate::class)->has(ProbeAbility::NAME))->toBeFalse();
});

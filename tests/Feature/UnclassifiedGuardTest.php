<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Testing\TrustApiGuardInConsole;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;

/*
 * The boot check classifies only the guards configured at boot. A guard added
 * later (a provider booting after the package, or at runtime) must still never
 * be treated as a web session: any default guard outside access.web_guards is
 * an API actor, so GateBefore grants its users no super-admin (re-review R1).
 */

beforeEach(function (): void {
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetScopedInstances();

    // PHPUnit runs in the console, where the guard signal is otherwise ignored.
    TrustApiGuardInConsole::enable();
});

afterEach(function (): void {
    TrustApiGuardInConsole::disable();
    AccessContext::clear();
});

it('gives no super-admin grant to users of a guard added after boot', function (): void {
    config()->set('auth.guards.late-token', ['driver' => 'session', 'provider' => 'users']);
    auth()->shouldUse('late-token');

    $superAdmin = new PermissionedFakeUser(1, ['super-admin']);

    expect(app(Gate::class)->forUser($superAdmin)->allows(FakePermissionEnum::CanEditUsers->value))->toBeFalse();

    auth()->shouldUse('web');

    expect(app(Gate::class)->forUser($superAdmin)->allows(FakePermissionEnum::CanEditUsers->value))->toBeTrue();
});

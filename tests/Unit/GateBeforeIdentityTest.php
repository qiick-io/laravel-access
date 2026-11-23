<?php

declare(strict_types=1);

use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\GateBefore;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Tests\Support\PermissionedFakeUser;

beforeEach(function (): void {
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetScopedInstances();
});

it('abstains when the bound context user is not the Gate user (N1 / §3.3)', function (): void {
    $bound = new PermissionedFakeUser(1, ['super-admin']);
    $other = new PermissionedFakeUser(2, ['super-admin']);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $bound));

    $gateBefore = app(GateBefore::class);

    expect($gateBefore($other, 'any-ability'))->toBeNull()
        ->and($gateBefore($bound, 'any-ability'))->toBeTrue();
});

it('abstains when the bound user and the Gate user share an identifier but not a type (§3.3)', function (): void {
    $bound = new PermissionedFakeUser(1);
    $otherGuardUser = new class(1, ['super-admin']) extends PermissionedFakeUser {};
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $bound));

    expect(app(GateBefore::class)($otherGuardUser, 'any-ability'))->toBeNull();
});

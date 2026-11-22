<?php

declare(strict_types=1);

use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Tests\Support\FakeAuthenticatable;

beforeEach(function (): void {
    AccessContext::clear();
});

afterEach(function (): void {
    AccessContext::clear();
});

it('stores and returns the entered actor context', function (): void {
    $context = new ActorContext(ActorKind::ApiKey, new FakeAuthenticatable, 'uuid-1');

    AccessContext::enter($context);

    expect(AccessContext::current())->toBe($context);
});

it('clears the bound context', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));
    AccessContext::clear();

    expect(AccessContext::current())->toBeNull();
});

it('replaces a previously entered context', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable(1)));
    $next = new ActorContext(ActorKind::OAuthGrant, new FakeAuthenticatable(2), 'uuid-2');
    AccessContext::enter($next);

    expect(AccessContext::current())->toBe($next);
});

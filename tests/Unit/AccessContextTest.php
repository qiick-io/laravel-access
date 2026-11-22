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

it('uses a scoped container instance so a fresh resolve after forgetScopedInstances is empty', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'leak'));
    app()->forgetScopedInstances();

    expect(AccessContext::current())->toBeNull();
});

it('does not leak an entered actor into the next test case after the app refreshes', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'previous-test'));
    $this->refreshApplication();

    expect(AccessContext::current())->toBeNull();
});

it('push and pop nest contexts for sync jobs', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'outer'));
    $store->push(new ActorContext(ActorKind::OAuthGrant, null, 'inner'));

    expect(AccessContext::current()?->credentialUuid)->toBe('inner');

    $store->pop();

    expect(AccessContext::current()?->credentialUuid)->toBe('outer');
});

it('pop restores frames in LIFO order', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'a'));
    $store->push(new ActorContext(ActorKind::OAuthGrant, null, 'b'));
    $store->push(new ActorContext(ActorKind::PublicApi, null, 'c'));

    $store->pop();
    expect(AccessContext::current()?->credentialUuid)->toBe('b');

    $store->pop();
    expect(AccessContext::current()?->credentialUuid)->toBe('a');
});

it('endJob restores nested sync job frames in LIFO order', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'a'));
    $store->beginJob(new ActorContext(ActorKind::OAuthGrant, null, 'b'), async: false);
    $store->beginJob(new ActorContext(ActorKind::PublicApi, null, 'c'), async: false);

    $store->endJob(async: false);
    expect(AccessContext::current()?->credentialUuid)->toBe('b');

    $store->endJob(async: false);
    expect(AccessContext::current()?->credentialUuid)->toBe('a');
});

it('pop on an empty nesting stack clears the current actor', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'alone'));
    app(AccessContext::class)->pop();

    expect(AccessContext::current())->toBeNull();
});

it('beginJob and endJob nest a sync job under the request actor', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request'));

    $store->beginJob(new ActorContext(ActorKind::OAuthGrant, null, 'job'), async: false);

    expect(AccessContext::current()?->credentialUuid)->toBe('job');

    $store->endJob(async: false);

    expect(AccessContext::current()?->credentialUuid)->toBe('request');
});

it('beginJob with a null stamp keeps the request actor for a sync job', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request'));

    $store->beginJob(null, async: false);

    expect(AccessContext::current()?->credentialUuid)->toBe('request');

    $store->endJob(async: false);

    expect(AccessContext::current()?->credentialUuid)->toBe('request');
});

it('beginJob and endJob replace the store on an async worker', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, null));

    $store->beginJob(new ActorContext(ActorKind::ApiKey, null, 'worker'), async: true);

    expect(AccessContext::current()?->credentialUuid)->toBe('worker');

    $store->endJob(async: true);

    expect(AccessContext::current())->toBeNull();
});

it('beginJob async with a null stamp leaves the worker unattended', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'stale'));

    $store->beginJob(null, async: true);

    expect(AccessContext::current())->toBeNull();

    $store->endJob(async: true);

    expect(AccessContext::current())->toBeNull();
});

it('clear drops nested frames so a later endJob cannot restore a stale actor', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request'));
    $store->beginJob(new ActorContext(ActorKind::OAuthGrant, null, 'job'), async: false);

    AccessContext::clear();
    AccessContext::enter(new ActorContext(ActorKind::WebSession, null));
    $store->endJob(async: false);

    expect(AccessContext::current()?->kind)->toBe(ActorKind::WebSession);
});

it('push then enter replaces only the current actor, not the saved outer frame', function (): void {
    $store = app(AccessContext::class);
    $outer = new ActorContext(ActorKind::ApiKey, null, 'outer');
    AccessContext::enter($outer);
    $store->push(new ActorContext(ActorKind::OAuthGrant, null, 'inner'));
    AccessContext::enter(new ActorContext(ActorKind::PublicApi, null, 'replaced'));

    expect(AccessContext::current()?->credentialUuid)->toBe('replaced');

    $store->pop();

    expect(AccessContext::current())->toBe($outer);
});

<?php

declare(strict_types=1);

use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\CarriesActorContext;

beforeEach(function (): void {
    AccessContext::clear();
});

afterEach(function (): void {
    AccessContext::clear();
});

it('re-binds the actor kind and credential UUID while the job runs', function (): void {
    $middleware = new CarriesActorContext(ActorKind::ApiKey, 'job-cred');
    $seen = null;

    $middleware->handle(new stdClass, function () use (&$seen) {
        $seen = AccessContext::current();

        return 'ok';
    });

    expect($seen)->toBeInstanceOf(ActorContext::class)
        ->and($seen->kind)->toBe(ActorKind::ApiKey)
        ->and($seen->credentialUuid)->toBe('job-cred')
        ->and($seen->isApiActor())->toBeTrue()
        ->and(AccessContext::current())->toBeNull();
});

it('restores the previous API actor after a nested job finishes', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request-cred'));

    $middleware = new CarriesActorContext(ActorKind::OAuthGrant, 'job-cred');
    $seen = null;

    $middleware->handle(new stdClass, function () use (&$seen) {
        $seen = AccessContext::current();

        return 'ok';
    });

    expect($seen?->kind)->toBe(ActorKind::OAuthGrant)
        ->and(AccessContext::current()?->kind)->toBe(ActorKind::ApiKey)
        ->and(AccessContext::current()?->credentialUuid)->toBe('request-cred');
});

it('restores the previous actor even when the job throws', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request-cred'));
    $middleware = new CarriesActorContext(ActorKind::OAuthGrant, 'g');

    try {
        $middleware->handle(new stdClass, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AccessContext::current()?->credentialUuid)->toBe('request-cred');
});

it('rejects non-API actor kinds', function (): void {
    expect(fn () => new CarriesActorContext(ActorKind::WebSession))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a credential UUID that does not match the bound actor of the same kind', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'bound-cred'));

    $middleware = new CarriesActorContext(ActorKind::ApiKey, 'other-cred');

    expect(fn () => $middleware->handle(new stdClass, fn () => null))
        ->toThrow(RuntimeException::class);
});

it('skips a second push when the payload stamp already matches', function (): void {
    $store = app(AccessContext::class);
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request-cred'));
    $store->beginJob(new ActorContext(ActorKind::ApiKey, null, 'same-cred'), async: false);

    $seen = null;
    (new CarriesActorContext(ActorKind::ApiKey, 'same-cred'))->handle(new stdClass, function () use (&$seen) {
        $seen = AccessContext::current();

        return 'ok';
    });

    $store->endJob(async: false);

    expect($seen?->credentialUuid)->toBe('same-cred')
        ->and(AccessContext::current()?->credentialUuid)->toBe('request-cred');
});

it('still rebinds when the bound actor has a different kind but the same UUID', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::OAuthGrant, null, 'shared-uuid'));

    $seen = null;
    (new CarriesActorContext(ActorKind::ApiKey, 'shared-uuid'))->handle(new stdClass, function () use (&$seen) {
        $seen = AccessContext::current();

        return 'ok';
    });

    expect($seen?->kind)->toBe(ActorKind::ApiKey)
        ->and(AccessContext::current()?->kind)->toBe(ActorKind::OAuthGrant);
});

it('allows a null-UUID bound actor when the middleware carries a UUID', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, null));

    $seen = null;
    (new CarriesActorContext(ActorKind::ApiKey, 'job-only'))->handle(new stdClass, function () use (&$seen) {
        $seen = AccessContext::current();

        return 'ok';
    });

    expect($seen?->credentialUuid)->toBe('job-only')
        ->and(AccessContext::current()?->credentialUuid)->toBeNull();
});

it('allows a null middleware credential when a UUID is already bound', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'bound-cred'));

    $seen = null;
    (new CarriesActorContext(ActorKind::ApiKey, null))->handle(new stdClass, function () use (&$seen) {
        $seen = AccessContext::current();

        return 'ok';
    });

    expect($seen?->credentialUuid)->toBeNull()
        ->and(AccessContext::current()?->credentialUuid)->toBe('bound-cred');
});

it('builds middleware only for a currently bound API actor', function (): void {
    expect(CarriesActorContext::forCurrentApiActor())->toBe([]);

    AccessContext::enter(new ActorContext(ActorKind::WebSession, null));
    expect(CarriesActorContext::forCurrentApiActor())->toBe([]);

    AccessContext::enter(new ActorContext(ActorKind::PublicApi, null, 'pub'));
    $middleware = CarriesActorContext::forCurrentApiActor();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0]->kind)->toBe(ActorKind::PublicApi)
        ->and($middleware[0]->credentialUuid)->toBe('pub');
});

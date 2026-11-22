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

it('clears the context even when the job throws', function (): void {
    $middleware = new CarriesActorContext(ActorKind::OAuthGrant, 'g');

    try {
        $middleware->handle(new stdClass, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AccessContext::current())->toBeNull();
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

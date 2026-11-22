<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\ContainerActorResolver;
use Qiick\Access\Tests\Support\FakeAuthenticatable;

beforeEach(function (): void {
    AccessContext::clear();
});

afterEach(function (): void {
    AccessContext::clear();
});

function makeResolver(mixed $apiGuards = [], string $defaultGuard = 'web'): ContainerActorResolver
{
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('access.api_guards', [])->andReturn($apiGuards);

    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('getDefaultDriver')->andReturn($defaultGuard);

    return new ContainerActorResolver($config, $auth);
}

it('returns Unattended when nothing is bound and no API guard is active', function (): void {
    $context = makeResolver()->current();

    expect($context->kind)->toBe(ActorKind::Unattended)
        ->and($context->user)->toBeNull()
        ->and($context->isApiActor())->toBeFalse();
});

it('returns the bound API actor unchanged even when an API guard is also active', function (): void {
    $user = new FakeAuthenticatable;
    AccessContext::enter(new ActorContext(ActorKind::OAuthGrant, $user, 'cred'));

    $context = makeResolver(['api_key'], 'api_key')->current();

    expect($context->kind)->toBe(ActorKind::OAuthGrant)
        ->and($context->user)->toBe($user)
        ->and($context->credentialUuid)->toBe('cred')
        ->and($context->isApiActor())->toBeTrue();
});

it('fails closed as an API actor when the default guard is an API guard', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable(9)));

    $context = makeResolver(['api_key'], 'api_key')->current();

    expect($context->isApiActor())->toBeTrue()
        ->and($context->kind)->toBe(ActorKind::ApiKey)
        ->and($context->user?->getAuthIdentifier())->toBe(9);
});

it('does not treat a non-API guard as an API actor', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $context = makeResolver(['api_key'], 'web')->current();

    expect($context->kind)->toBe(ActorKind::WebSession)
        ->and($context->isApiActor())->toBeFalse();
});

it('ignores a non-array api_guards config value', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $context = makeResolver('api_key', 'api_key')->current();

    expect($context->isApiActor())->toBeFalse();
});

it('ignores empty and non-string entries in api_guards', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver(['', null, 1], 'api_key')->current()->isApiActor())->toBeFalse();
    expect(makeResolver(['', 'api_key'], 'api_key')->current()->isApiActor())->toBeTrue();
});

it('does not treat an empty-string guard name as an API guard', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    // Kills EmptyStringToNotEmpty on the guard filter: '' must stay excluded.
    expect(makeResolver([''], '')->current()->isApiActor())->toBeFalse();
});

it('does not treat a null default driver as matching a null config entry', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    // Kills BooleanAndToBooleanOr on the guard filter: null must stay excluded.
    expect(makeResolver([null], '')->current()->isApiActor())->toBeFalse();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('access.api_guards', [])->andReturn([null]);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('getDefaultDriver')->andReturn(null);

    expect((new ContainerActorResolver($config, $auth))->current()->isApiActor())->toBeFalse();
});

it('never reads request input when resolving the actor', function (): void {
    request()->headers->set('X-Actor', 'web');
    request()->query->set('actor', 'web');
    request()->request->set('actor', 'web');
    request()->cookies->set('actor', 'web');

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, new FakeAuthenticatable, 'k'));

    $context = makeResolver()->current();

    expect($context->kind)->toBe(ActorKind::ApiKey)
        ->and($context->isApiActor())->toBeTrue();
});

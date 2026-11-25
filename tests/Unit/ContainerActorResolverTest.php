<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
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

function makeResolver(
    mixed $apiGuards = [],
    string $defaultGuard = 'web',
    bool $runningInConsole = false,
    bool $trustApiGuardInConsole = false,
    bool $isTestingEnvironment = true,
    mixed $webGuards = ['web'],
): ContainerActorResolver {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('access.api_guards', [])->andReturn($apiGuards);
    $config->shouldReceive('get')->with('access.web_guards', [])->andReturn($webGuards);
    $config->shouldReceive('get')->with('access.trust_api_guard_in_console', false)->andReturn($trustApiGuardInConsole);

    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('getDefaultDriver')->andReturn($defaultGuard);

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn($runningInConsole);
    $app->shouldReceive('environment')->with('testing')->andReturn($isTestingEnvironment);

    return new ContainerActorResolver($config, $auth, $app);
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

it('fails closed as an API actor when the default guard is an API guard on HTTP', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable(9)));

    $context = makeResolver(['api_key'], 'api_key', runningInConsole: false)->current();

    expect($context->isApiActor())->toBeTrue()
        ->and($context->kind)->toBe(ActorKind::ApiKey)
        ->and($context->user?->getAuthIdentifier())->toBe(9);
});

it('ignores the API guard signal in console jobs and commands', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $context = makeResolver(['api_key'], 'api_key', runningInConsole: true)->current();

    expect($context->kind)->toBe(ActorKind::WebSession)
        ->and($context->isApiActor())->toBeFalse();
});

it('does not treat a non-API guard as an API actor', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $context = makeResolver(['api_key'], 'web')->current();

    expect($context->kind)->toBe(ActorKind::WebSession)
        ->and($context->isApiActor())->toBeFalse();
});

// Re-review R1: any default guard outside access.web_guards is an API guard,
// so malformed or empty guard lists fail closed instead of passing as web.
it('treats a guard outside web_guards as an API guard, listed in api_guards or not', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver([], 'late-token')->current()->isApiActor())->toBeTrue()
        ->and(makeResolver('api_key', 'api_key')->current()->isApiActor())->toBeTrue()
        ->and(makeResolver([], 'web')->current()->isApiActor())->toBeFalse();
});

it('treats a guard listed in both web_guards and api_guards as an API guard', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver(['web'], 'web')->current()->isApiActor())->toBeTrue();
});

it('fails closed on a non-array web_guards config value', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver([], 'web', webGuards: 'web')->current()->isApiActor())->toBeTrue();
});

it('ignores empty and non-string entries in the guard lists', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver([], 'web', webGuards: ['', null, 1])->current()->isApiActor())->toBeTrue()
        ->and(makeResolver([], 'web', webGuards: ['', 'web'])->current()->isApiActor())->toBeFalse()
        ->and(makeResolver(['', null, 1], 'web')->current()->isApiActor())->toBeFalse()
        ->and(makeResolver(['', 'api_key'], 'api_key', webGuards: ['web', 'api_key'])->current()->isApiActor())->toBeTrue();
});

it('never treats an empty or null default guard as a web guard', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver([], '', webGuards: [''])->current()->isApiActor())->toBeTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('access.api_guards', [])->andReturn([]);
    $config->shouldReceive('get')->with('access.web_guards', [])->andReturn([null]);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('getDefaultDriver')->andReturn(null);
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn(false);

    expect((new ContainerActorResolver($config, $auth, $app))->current()->isApiActor())->toBeTrue();
});

it('never reads request input when resolving a bound API actor', function (): void {
    request()->headers->set('X-Actor', 'web');
    request()->query->set('actor', 'web');
    request()->request->set('actor', 'web');
    request()->cookies->set('actor', 'web');

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, new FakeAuthenticatable, 'k'));

    expect(makeResolver()->current()->kind)->toBe(ActorKind::ApiKey)
        ->and(makeResolver()->current()->isApiActor())->toBeTrue();
});

it('ignores forged request input when nothing is bound', function (): void {
    request()->headers->set('X-Actor', 'api_key');
    request()->query->set('actor', 'api_key');
    request()->request->set('actor', 'api_key');
    request()->cookies->set('actor', 'api_key');

    expect(makeResolver()->current()->kind)->toBe(ActorKind::Unattended)
        ->and(makeResolver()->current()->isApiActor())->toBeFalse();
});

it('ignores forged request input when a web session is bound', function (): void {
    request()->headers->set('X-Actor', 'api_key');
    request()->query->set('actor', 'api_key');
    request()->request->set('actor', 'api_key');
    request()->cookies->set('actor', 'api_key');

    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    expect(makeResolver()->current()->kind)->toBe(ActorKind::WebSession)
        ->and(makeResolver()->current()->isApiActor())->toBeFalse();
});

it('keeps the API guard signal when forged input claims web (AC4)', function (): void {
    request()->headers->set('X-Actor', 'web');
    request()->query->set('actor', 'web');
    request()->request->set('actor', 'web');
    request()->cookies->set('actor', 'web');

    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable(3)));

    $context = makeResolver(['api_key'], 'api_key', runningInConsole: false)->current();

    expect($context->isApiActor())->toBeTrue()
        ->and($context->kind)->toBe(ActorKind::ApiKey)
        ->and($context->user?->getAuthIdentifier())->toBe(3);
});

it('honours the API guard in console when trust_api_guard_in_console is enabled in testing', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $context = makeResolver(
        ['api_key'],
        'api_key',
        runningInConsole: true,
        trustApiGuardInConsole: true,
        isTestingEnvironment: true,
    )->current();

    expect($context->isApiActor())->toBeTrue()
        ->and($context->kind)->toBe(ActorKind::ApiKey);
});

it('ignores trust_api_guard_in_console outside the testing environment', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $context = makeResolver(
        ['api_key'],
        'api_key',
        runningInConsole: true,
        trustApiGuardInConsole: true,
        isTestingEnvironment: false,
    )->current();

    expect($context->kind)->toBe(ActorKind::WebSession)
        ->and($context->isApiActor())->toBeFalse();
});

it('ignores a non-true trust_api_guard_in_console value in console', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, new FakeAuthenticatable));

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('access.api_guards', [])->andReturn(['api_key']);
    $config->shouldReceive('get')->with('access.trust_api_guard_in_console', false)->andReturn(1);

    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('getDefaultDriver')->andReturn('api_key');

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn(true);
    $app->shouldReceive('environment')->with('testing')->andReturn(true);

    expect((new ContainerActorResolver($config, $auth, $app))->current()->isApiActor())->toBeFalse();
});

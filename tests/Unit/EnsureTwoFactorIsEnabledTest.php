<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Qiick\Access\Http\EnsureTwoFactorIsEnabled;
use Qiick\Access\Tests\Support\FakeAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

function twoFactorMiddleware(
    ActorResolver $actors,
    TwoFactorPolicy $policy,
    TwoFactorResponder $responder,
    array $exemptRouteNames = ['logout', 'security.*', 'two-factor.*', 'passkey.*', 'password.confirm*'],
): EnsureTwoFactorIsEnabled {
    config()->set('access.two_factor.exempt_route_names', $exemptRouteNames);

    return new EnsureTwoFactorIsEnabled(
        $actors,
        $policy,
        $responder,
        app('config'),
    );
}

function requestWithUser(?FakeAuthenticatable $user, ?string $routeName = 'dashboard'): Request
{
    $request = Request::create('/dashboard', 'GET');

    if ($user !== null) {
        $request->setUserResolver(static fn () => $user);
    }

    if ($routeName !== null) {
        $route = new Route(['GET'], '/dashboard', ['as' => $routeName]);
        $route->name($routeName);
        $request->setRouteResolver(static fn () => $route);
    }

    return $request;
}

it('passes through when nobody is authenticated', function (): void {
    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->never();

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->never();

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->never();
    $responder->shouldReceive('unavailable')->never();

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle(requestWithUser(null), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through exempt route names including wildcards (R9 / AC10)', function (): void {
    $user = new FakeAuthenticatable(1);
    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->never();

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->never();

    $responder = Mockery::mock(TwoFactorResponder::class);

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle(
        requestWithUser($user, 'security.edit'),
        static fn () => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('passes through API actors without enrolment (AC10)', function (): void {
    $user = new FakeAuthenticatable(2);
    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::ApiKey, $user, 'key-uuid'));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->never();

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->never();

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle(requestWithUser($user), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through when 2FA is not required', function (): void {
    $user = new FakeAuthenticatable(3);
    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(false);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->never();

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle(requestWithUser($user), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through when the user already has a usable factor', function (): void {
    $user = new FakeAuthenticatable(4);
    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(true);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->never();

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle(requestWithUser($user), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('returns unavailable when no second-factor method is enabled', function (): void {
    $user = new FakeAuthenticatable(5);
    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(false);
    $policy->shouldReceive('isAnyMethodEnabled')->andReturn(false);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('unavailable')->once()->andReturn(new Response('unavailable', 503));
    $responder->shouldReceive('enrol')->never();

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle(requestWithUser($user), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('unavailable')
        ->and($response->getStatusCode())->toBe(503);
});

it('returns enrol when 2FA is required and unsatisfied (AC10)', function (): void {
    $user = new FakeAuthenticatable(6);
    $request = requestWithUser($user);

    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(false);
    $policy->shouldReceive('isAnyMethodEnabled')->andReturn(true);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->once()->with($request)->andReturn(new Response('enrol', 302));

    $middleware = twoFactorMiddleware($actors, $policy, $responder);
    $response = $middleware->handle($request, static fn () => new Response('ok'));

    expect($response->getContent())->toBe('enrol')
        ->and($response->getStatusCode())->toBe(302);
});

it('does not treat a missing or unnamed route as exempt', function (): void {
    $user = new FakeAuthenticatable(7);

    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(false);
    $policy->shouldReceive('isAnyMethodEnabled')->andReturn(true);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->twice()->andReturn(new Response('enrol', 302));

    // '*' would match an empty name via Str::is; empty names must still enrol.
    $middleware = twoFactorMiddleware($actors, $policy, $responder, ['*']);

    $withoutRoute = Request::create('/dashboard', 'GET');
    $withoutRoute->setUserResolver(static fn () => $user);

    $unnamed = requestWithUser($user, '');

    expect($middleware->handle($withoutRoute, static fn () => new Response('ok'))->getContent())->toBe('enrol')
        ->and($middleware->handle($unnamed, static fn () => new Response('ok'))->getContent())->toBe('enrol');
});

it('ignores empty and non-string exempt patterns ahead of a matching one', function (): void {
    $user = new FakeAuthenticatable(8);

    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->never();

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->never();

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->never();

    $middleware = twoFactorMiddleware($actors, $policy, $responder, ['', 12, 'security.*']);
    $response = $middleware->handle(requestWithUser($user, 'security.edit'), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('does not let a non-string pattern that stringifies to the route name exempt it', function (): void {
    $user = new FakeAuthenticatable(10);

    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(false);
    $policy->shouldReceive('isAnyMethodEnabled')->andReturn(true);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->once()->andReturn(new Response('enrol', 302));

    // Str::is(12, '12') is true; the pattern must still be skipped as non-string.
    $middleware = twoFactorMiddleware($actors, $policy, $responder, [12]);
    $response = $middleware->handle(requestWithUser($user, '12'), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('enrol');
});

it('does not let an empty-string pattern match via Str::is against an empty name', function (): void {
    // Empty route names are rejected before patterns; empty patterns must not
    // become a wildcard when the empty-string guard is mutated away in isolation.
    $user = new FakeAuthenticatable(11);

    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(false);
    $policy->shouldReceive('isAnyMethodEnabled')->andReturn(true);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->once()->andReturn(new Response('enrol', 302));

    $middleware = twoFactorMiddleware($actors, $policy, $responder, ['']);
    $response = $middleware->handle(requestWithUser($user, 'dashboard'), static fn () => new Response('ok'));

    expect($response->getContent())->toBe('enrol');
});

it('does not treat a non-array exempt config as exempt', function (): void {
    $user = new FakeAuthenticatable(9);

    $actors = Mockery::mock(ActorResolver::class);
    $actors->shouldReceive('current')->andReturn(new ActorContext(ActorKind::WebSession, $user));

    $policy = Mockery::mock(TwoFactorPolicy::class);
    $policy->shouldReceive('isRequired')->with($user)->andReturn(true);
    $policy->shouldReceive('hasUsableFactor')->with($user)->andReturn(false);
    $policy->shouldReceive('isAnyMethodEnabled')->andReturn(true);

    $responder = Mockery::mock(TwoFactorResponder::class);
    $responder->shouldReceive('enrol')->once()->andReturn(new Response('enrol', 302));

    config()->set('access.two_factor.exempt_route_names', 'logout');
    $middleware = new EnsureTwoFactorIsEnabled($actors, $policy, $responder, app('config'));

    expect($middleware->handle(requestWithUser($user, 'logout'), static fn () => new Response('ok'))->getContent())->toBe('enrol');
});

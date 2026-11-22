<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\ContainerActorResolver;

beforeEach(function (): void {
    AccessContext::clear();
});

afterEach(function (): void {
    AccessContext::clear();
});

it('binds ContainerActorResolver as the ActorResolver', function (): void {
    expect(app(ActorResolver::class))->toBeInstanceOf(ContainerActorResolver::class);
});

it('binds AccessContext as a scoped instance', function (): void {
    expect(app(AccessContext::class))->toBeInstanceOf(AccessContext::class)
        ->and(app(AccessContext::class))->toBe(app(AccessContext::class));
});

it('keeps the API actor through terminating and defer callbacks', function (): void {
    $seenInTerminate = null;
    $seenInDefer = null;

    Route::get('/actor-terminate', function () use (&$seenInDefer, &$seenInTerminate) {
        AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'term-cred'));
        defer(function () use (&$seenInDefer): void {
            $seenInDefer = AccessContext::current();
        });
        app()->terminating(function () use (&$seenInTerminate): void {
            $seenInTerminate = AccessContext::current();
        });

        return response('ok');
    });

    $this->get('/actor-terminate')->assertOk();

    expect($seenInTerminate?->kind)->toBe(ActorKind::ApiKey)
        ->and($seenInTerminate?->credentialUuid)->toBe('term-cred')
        ->and($seenInDefer?->kind)->toBe(ActorKind::ApiKey)
        ->and($seenInDefer?->credentialUuid)->toBe('term-cred');
});

it('clears the actor when scoped instances are flushed between requests', function (): void {
    Route::get('/first', function () {
        AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'first'));

        return response('1');
    });
    Route::get('/second', function () {
        return response(AccessContext::current() === null ? 'cleared' : 'leaked');
    });

    $this->get('/first')->assertOk();
    // FPM starts a new process; Octane fires RequestReceived → clear().
    // In one Testbench process, flush scoped instances between requests.
    app()->forgetScopedInstances();
    $this->get('/second')->assertOk()->assertSee('cleared');
});

it('keeps the API actor after an internal sub-request so Gate still abstains', function (): void {
    Gate::before(function () {
        if (app(ActorResolver::class)->current()->isApiActor()) {
            return null;
        }

        return true;
    });
    Gate::define('sub-request-probe', fn () => false);

    Route::get('/inner', fn () => response('inner'));
    Route::get('/outer', function () {
        AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'outer-cred'));

        $subResponse = app()->handle(Request::create('/inner', 'GET'));
        expect($subResponse->getContent())->toBe('inner');

        $stillApi = app(ActorResolver::class)->current()->isApiActor();
        $gateSeesApi = Gate::allows('sub-request-probe') === false;

        return response($stillApi && $gateSeesApi ? 'api' : 'lost');
    });

    $this->get('/outer')->assertOk()->assertSee('api');
});

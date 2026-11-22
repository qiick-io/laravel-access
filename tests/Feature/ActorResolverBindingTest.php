<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Events\RequestHandled;
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

it('clears AccessContext when RequestHandled is dispatched', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'x'));

    event(new RequestHandled(
        request(),
        response(''),
    ));

    expect(AccessContext::current())->toBeNull();
});

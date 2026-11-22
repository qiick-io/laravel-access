<?php

declare(strict_types=1);

use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Tests\Support\FakeAuthenticatable;

it('marks api key, oauth grant and public api as API actors', function (ActorKind $kind): void {
    $context = new ActorContext($kind, new FakeAuthenticatable, '11111111-1111-4111-8111-111111111111');

    expect($context->isApiActor())->toBeTrue();
})->with([
    ActorKind::ApiKey,
    ActorKind::OAuthGrant,
    ActorKind::PublicApi,
]);

it('does not mark web sessions or unattended work as API actors', function (ActorKind $kind): void {
    $context = new ActorContext($kind, new FakeAuthenticatable);

    expect($context->isApiActor())->toBeFalse();
})->with([
    ActorKind::WebSession,
    ActorKind::Unattended,
]);

it('allows a null user for the public actor', function (): void {
    $context = new ActorContext(ActorKind::PublicApi, null);

    expect($context->user)->toBeNull()
        ->and($context->isApiActor())->toBeTrue()
        ->and($context->credentialUuid)->toBeNull();
});

it('keeps the credential UUID when provided', function (): void {
    $uuid = '22222222-2222-4222-8222-222222222222';
    $user = new FakeAuthenticatable(42);
    $context = new ActorContext(ActorKind::ApiKey, $user, $uuid);

    expect($context->kind)->toBe(ActorKind::ApiKey)
        ->and($context->user)->toBe($user)
        ->and($context->credentialUuid)->toBe($uuid);
});

it('exposes every ActorKind case from the design', function (): void {
    expect(array_map(
        static fn (ActorKind $kind): string => $kind->value,
        ActorKind::cases(),
    ))->toBe([
        'web_session',
        'api_key',
        'oauth_grant',
        'public_api',
        'unattended',
    ]);
});

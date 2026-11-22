<?php

declare(strict_types=1);

use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\ActorPayloadStamper;

beforeEach(function (): void {
    AccessContext::clear();
});

afterEach(function (): void {
    AccessContext::clear();
});

it('stamps an empty array when no actor is bound', function (): void {
    expect((new ActorPayloadStamper)->stamp())->toBe([]);
});

it('stamps an empty array for a non-API actor', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, null));

    expect((new ActorPayloadStamper)->stamp())->toBe([]);
});

it('stamps kind and credential UUID for an API actor', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'stamp-uuid'));

    expect((new ActorPayloadStamper)->stamp())->toBe([
        ActorPayloadStamper::PAYLOAD_KEY => [
            'kind' => 'api_key',
            'credential_uuid' => 'stamp-uuid',
        ],
    ]);
});

it('rebuilds an API actor from a stamped payload', function (): void {
    $stamper = new ActorPayloadStamper;
    $actor = $stamper->actorFromPayload([
        ActorPayloadStamper::PAYLOAD_KEY => [
            'kind' => 'oauth_grant',
            'credential_uuid' => 'from-payload',
        ],
    ]);

    expect($actor)->toBeInstanceOf(ActorContext::class)
        ->and($actor->kind)->toBe(ActorKind::OAuthGrant)
        ->and($actor->credentialUuid)->toBe('from-payload')
        ->and($actor->isApiActor())->toBeTrue();
});

it('returns null from a missing or non-API stamp', function (): void {
    $stamper = new ActorPayloadStamper;

    expect($stamper->actorFromPayload([]))->toBeNull()
        ->and($stamper->actorFromPayload([ActorPayloadStamper::PAYLOAD_KEY => 'bad']))->toBeNull()
        ->and($stamper->actorFromPayload([ActorPayloadStamper::PAYLOAD_KEY => ['kind' => 'nope']]))->toBeNull()
        ->and($stamper->actorFromPayload([ActorPayloadStamper::PAYLOAD_KEY => ['kind' => 'web_session']]))->toBeNull()
        ->and($stamper->actorFromPayload([ActorPayloadStamper::PAYLOAD_KEY => ['kind' => 1]]))->toBeNull()
        ->and($stamper->actorFromPayload([ActorPayloadStamper::PAYLOAD_KEY => ['kind' => null]]))->toBeNull();
});

it('ignores a non-string credential UUID in the stamp', function (): void {
    $stamper = new ActorPayloadStamper;
    $actor = $stamper->actorFromPayload([
        ActorPayloadStamper::PAYLOAD_KEY => [
            'kind' => 'api_key',
            'credential_uuid' => 99,
        ],
    ]);

    expect($actor)->toBeInstanceOf(ActorContext::class)
        ->and($actor->credentialUuid)->toBeNull();
});

it('treats sync as nested and other connections as async', function (): void {
    $stamper = new ActorPayloadStamper;

    expect($stamper->isAsyncConnection('sync'))->toBeFalse()
        ->and($stamper->isAsyncConnection('database'))->toBeTrue()
        ->and($stamper->isAsyncConnection('redis'))->toBeTrue();
});

it('beginJob and endJob on a sync connection nest under the request actor', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request'));
    $stamper = new ActorPayloadStamper;

    $stamper->beginJob([
        ActorPayloadStamper::PAYLOAD_KEY => [
            'kind' => 'oauth_grant',
            'credential_uuid' => 'job',
        ],
    ], 'sync');

    expect(AccessContext::current()?->credentialUuid)->toBe('job');

    $stamper->endJob('sync');

    expect(AccessContext::current()?->credentialUuid)->toBe('request');
});

it('beginJob and endJob on an async connection replace the worker store', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::WebSession, null));
    $stamper = new ActorPayloadStamper;

    $stamper->beginJob([
        ActorPayloadStamper::PAYLOAD_KEY => [
            'kind' => 'api_key',
            'credential_uuid' => 'worker',
        ],
    ], 'database');

    expect(AccessContext::current()?->credentialUuid)->toBe('worker');

    $stamper->endJob('database');

    expect(AccessContext::current())->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\ActorPayloadStamper;
use Qiick\Access\Laravel\CarriesActorContext;
use Qiick\Access\Tests\Support\Jobs\CaptureActorJob;

beforeEach(function (): void {
    AccessContext::clear();
    CaptureActorJob::reset();
});

afterEach(function (): void {
    AccessContext::clear();
    CaptureActorJob::reset();
});

it('restores the request API actor after a sync nested job', function (): void {
    Gate::before(function () {
        // Stand-in for GateBefore step 2: abstain for API actors.
        if (app(ActorResolver::class)->current()->isApiActor()) {
            return null;
        }

        return true;
    });
    Gate::define('probe-ability', fn () => false);

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request-cred'));

    dispatch_sync(new CaptureActorJob);

    expect(CaptureActorJob::$captures)->toHaveCount(1)
        ->and(CaptureActorJob::$captures[0]['kind'])->toBe('api_key')
        ->and(CaptureActorJob::$captures[0]['uuid'])->toBe('request-cred')
        ->and(AccessContext::current()?->kind)->toBe(ActorKind::ApiKey)
        ->and(AccessContext::current()?->credentialUuid)->toBe('request-cred')
        ->and(app(ActorResolver::class)->current()->isApiActor())->toBeTrue()
        // After the sync job, a Gate check must still see the API actor (abstain → false).
        ->and(Gate::allows('probe-ability'))->toBeFalse();
});

it('restores the request API actor when a job runs on the sync queue connection', function (): void {
    config()->set('queue.default', 'sync');

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'sync-conn-cred'));

    CaptureActorJob::dispatch();

    expect(CaptureActorJob::$captures)->toHaveCount(1)
        ->and(CaptureActorJob::$captures[0]['kind'])->toBe('api_key')
        ->and(CaptureActorJob::$captures[0]['uuid'])->toBe('sync-conn-cred')
        ->and(AccessContext::current()?->credentialUuid)->toBe('sync-conn-cred')
        ->and(app(ActorResolver::class)->current()->isApiActor())->toBeTrue();
});

it('restores the request API actor when CarriesActorContext middleware is used', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'request-cred'));

    $job = (new CaptureActorJob)->through([
        new CarriesActorContext(ActorKind::ApiKey, 'request-cred'),
    ]);
    dispatch_sync($job);

    expect(AccessContext::current()?->credentialUuid)->toBe('request-cred')
        ->and(app(ActorResolver::class)->current()->isApiActor())->toBeTrue();
});

it('does not leak an API actor from job A to job B on the same worker', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'job-a'));
    dispatch_sync(new CaptureActorJob);

    AccessContext::clear();
    CaptureActorJob::reset();

    dispatch_sync(new CaptureActorJob);

    expect(CaptureActorJob::$captures[0]['kind'])->toBeNull()
        ->and(AccessContext::current())->toBeNull();
});

it('stamps the API actor into the database queue payload at dispatch', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    app('db')->purge();
    app('db')->reconnect();

    Schema::create('jobs', function ($table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'stamped-cred'));
    CaptureActorJob::dispatch();

    $row = app('db')->table('jobs')->first();
    expect($row)->not->toBeNull();
    $payload = json_decode($row->payload, true);
    expect($payload[ActorPayloadStamper::PAYLOAD_KEY]['kind'])->toBe('api_key')
        ->and($payload[ActorPayloadStamper::PAYLOAD_KEY]['credential_uuid'])->toBe('stamped-cred');
});

it('keeps illuminate log context next to the actor stamp in the job payload', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    app('db')->purge();
    app('db')->reconnect();

    Schema::create('jobs', function ($table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    // Simulate another package's createPayloadUsing hook (Laravel log context).
    Queue::createPayloadUsing(static fn (): array => [
        'illuminate:log:context' => ['request_id' => 'abc-123'],
    ]);

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'with-log-context'));
    CaptureActorJob::dispatch();

    $row = app('db')->table('jobs')->first();
    $payload = json_decode($row->payload, true);

    expect($payload[ActorPayloadStamper::PAYLOAD_KEY]['credential_uuid'])->toBe('with-log-context')
        ->and($payload['illuminate:log:context']['request_id'])->toBe('abc-123');
});

it('re-binds the stamped API actor when a database queue worker runs the job (AC5)', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    app('db')->purge();
    app('db')->reconnect();

    Schema::create('jobs', function ($table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, null, 'db-queue-cred'));
    CaptureActorJob::dispatch();
    AccessContext::clear();

    expect(AccessContext::current())->toBeNull();

    $this->artisan('queue:work', [
        '--once' => true,
        '--queue' => 'default',
    ])->assertSuccessful();

    expect(CaptureActorJob::$captures)->toHaveCount(1)
        ->and(CaptureActorJob::$captures[0]['kind'])->toBe('api_key')
        ->and(CaptureActorJob::$captures[0]['uuid'])->toBe('db-queue-cred')
        ->and(AccessContext::current())->toBeNull();
});

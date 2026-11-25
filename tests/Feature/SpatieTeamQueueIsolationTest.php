<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\Laravel\SpatieTeamContext;
use Qiick\Access\Tests\Support\Jobs\CaptureTeamIdJob;
use Qiick\Access\Tests\Support\Jobs\SwitchTeamJob;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

beforeEach(function (): void {
    CaptureTeamIdJob::reset();

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

    $this->app->register(PermissionServiceProvider::class);
    $this->app->singleton(TeamContext::class, SpatieTeamContext::class);
});

afterEach(function (): void {
    CaptureTeamIdJob::reset();
    Schema::dropIfExists('jobs');
});

it('resets the Spatie team id between database queue workers so job 2 does not inherit job 1', function (): void {
    SwitchTeamJob::dispatch('org-A');
    CaptureTeamIdJob::dispatch();

    expect(app('db')->table('jobs')->count())->toBe(2);

    $this->artisan('queue:work', [
        '--once' => true,
        '--queue' => 'default',
    ])->assertSuccessful();

    // After job 1 (switchTo org-A), the provider must have cleared the team again.
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();

    $this->artisan('queue:work', [
        '--once' => true,
        '--queue' => 'default',
    ])->assertSuccessful();

    expect(CaptureTeamIdJob::$teamIds)->toHaveCount(1)
        ->and(CaptureTeamIdJob::$teamIds[0])->toBeNull()
        ->and(app('db')->table('jobs')->count())->toBe(0);
})->group('engines');

it('restores the request Spatie team after a sync-connection nested job', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('queue.connections.sync', ['driver' => 'sync']);

    app(TeamContext::class)->switchTo('org-A');
    expect(app(TeamContext::class)->currentId())->toBe('org-A');

    SwitchTeamJob::dispatch('org-B');

    expect(app(TeamContext::class)->currentId())->toBe('org-A')
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe('org-A');
});

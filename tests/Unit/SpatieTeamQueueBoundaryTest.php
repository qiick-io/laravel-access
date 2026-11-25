<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Qiick\Access\Laravel\SpatieTeamQueueBoundary;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

beforeEach(function (): void {
    $this->app->register(PermissionServiceProvider::class);
});

it('clears the team before and after an async connection job', function (): void {
    $boundary = app(SpatieTeamQueueBoundary::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-A');

    $boundary->begin('database');
    expect($registrar->getPermissionsTeamId())->toBeNull();

    $registrar->setPermissionsTeamId('org-B');
    $boundary->end('database');
    expect($registrar->getPermissionsTeamId())->toBeNull();
});

it('saves and restores the request team around a sync connection job', function (): void {
    $boundary = app(SpatieTeamQueueBoundary::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-A');

    $boundary->begin('sync');
    expect($registrar->getPermissionsTeamId())->toBe('org-A');

    $registrar->setPermissionsTeamId('org-B');
    $boundary->end('sync');
    expect($registrar->getPermissionsTeamId())->toBe('org-A');
});

it('nests sync frames in LIFO order', function (): void {
    $boundary = app(SpatieTeamQueueBoundary::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('outer');

    $boundary->begin('sync');
    $registrar->setPermissionsTeamId('inner');
    $boundary->begin('sync');
    $registrar->setPermissionsTeamId('deep');
    $boundary->end('sync');
    expect($registrar->getPermissionsTeamId())->toBe('inner');
    $boundary->end('sync');
    expect($registrar->getPermissionsTeamId())->toBe('outer');
});

it('treats only the sync connection as nested', function (): void {
    $boundary = app(SpatieTeamQueueBoundary::class);

    expect($boundary->isAsyncConnection('sync'))->toBeFalse()
        ->and($boundary->isAsyncConnection('database'))->toBeTrue();
});

it('does not restore a sync-saved team when ending an async job', function (): void {
    $boundary = app(SpatieTeamQueueBoundary::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-A');

    $boundary->begin('sync');
    $registrar->setPermissionsTeamId('org-B');
    $boundary->begin('database');
    expect($registrar->getPermissionsTeamId())->toBeNull();

    $boundary->end('database');
    expect($registrar->getPermissionsTeamId())->toBeNull();

    $boundary->end('sync');
    expect($registrar->getPermissionsTeamId())->toBe('org-A');
});

it('clears the team when ending sync with an empty save stack', function (): void {
    $boundary = app(SpatieTeamQueueBoundary::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId('org-A');

    $boundary->end('sync');
    expect($registrar->getPermissionsTeamId())->toBeNull();
});

it('flushes Spatie permission cache when resetting the team for an async job', function (): void {
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('getPermissionsTeamId')->never();
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with(null);
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $this->app->instance(PermissionRegistrar::class, $registrar);

    app(SpatieTeamQueueBoundary::class)->begin('database');
});

it('nests sync frames safely when PermissionRegistrar is not bound', function (): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('bound')->with(PermissionRegistrar::class)->andReturn(false);
    $application->shouldReceive('make')->never();

    $boundary = new SpatieTeamQueueBoundary($application);
    $boundary->begin('sync');
    $boundary->end('sync');
    $boundary->begin('database');
    $boundary->end('database');
});

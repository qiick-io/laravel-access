<?php

declare(strict_types=1);

use Qiick\Access\Laravel\SpatieTeamQueueBoundary;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

beforeEach(function (): void {
    $this->app->register(PermissionServiceProvider::class);
});

it('does not cross-restore teams when two request scopes nest sync jobs', function (): void {
    $registrar = app(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId('org-A');
    $boundaryA = app(SpatieTeamQueueBoundary::class);
    $boundaryA->begin('sync');

    // Second concurrent request (Octane): new scope must not share A's save stack.
    app()->forgetScopedInstances();

    $registrar->setPermissionsTeamId('org-B');
    $boundaryB = app(SpatieTeamQueueBoundary::class);
    expect($boundaryB)->not->toBe($boundaryA);

    $boundaryB->begin('sync');
    $registrar->setPermissionsTeamId('dirty');

    $boundaryA->end('sync');
    expect($registrar->getPermissionsTeamId())->toBe('org-A');

    $registrar->setPermissionsTeamId('dirty-again');
    $boundaryB->end('sync');
    expect($registrar->getPermissionsTeamId())->toBe('org-B');
});

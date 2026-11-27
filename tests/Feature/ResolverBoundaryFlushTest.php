<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakeAuthenticatable;

/*
 * The provider flushes the current resolver at every request and job
 * boundary (AC11), even where the container keeps the same scoped instance
 * (the sync queue, a nested job).
 */

beforeEach(function (): void {
    $this->groupRoleLookups = 0;
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturnUsing(function (): array {
        $this->groupRoleLookups++;

        return [];
    });
    app()->instance(GroupRoleSource::class, $groupRoles);
    app()->forgetInstance(EffectivePermissionResolver::class);
});

/**
 * Asks the same resolver twice, with the boundary in between, and returns
 * how often it went back to the group source.
 */
function groupLookupsAround(Closure $boundary): int
{
    $resolver = app(EffectivePermissionResolver::class);
    $user = new FakeAuthenticatable(1);

    $resolver->hasPermission($user, 'can-show-users');
    $boundary();
    $resolver->hasPermission($user, 'can-show-users');

    return test()->groupRoleLookups;
}

function syncJob(): SyncJob
{
    return new SyncJob(app(), (string) json_encode(['uuid' => 'job', 'displayName' => 'Job', 'job' => 'Job', 'data' => []]), 'sync', 'default');
}

it('keeps a decision inside one boundary', function (): void {
    expect(groupLookupsAround(static function (): void {}))->toBe(1);
});

it('flushes the resolver when a request is handled (AC11)', function (): void {
    expect(groupLookupsAround(fn () => app(Dispatcher::class)->dispatch(new RequestHandled(Request::create('/'), new Response))))->toBe(2);
});

it('flushes the resolver when a job starts (AC11)', function (): void {
    expect(groupLookupsAround(fn () => app(Dispatcher::class)->dispatch(new JobProcessing('sync', syncJob()))))->toBe(2);
});

it('flushes the resolver when a job ends or fails (AC11)', function (string $outcome): void {
    app(Dispatcher::class)->dispatch(new JobProcessing('sync', syncJob()));
    $endEvent = $outcome === 'processed'
        ? new JobProcessed('sync', syncJob())
        : new JobFailed('sync', syncJob(), new RuntimeException('failed'));

    expect(groupLookupsAround(fn () => app(Dispatcher::class)->dispatch($endEvent)))->toBe(2);
})->with(['processed', 'failed']);

it('refuses an empty permission enum when building the vocabulary', function (): void {
    config()->set('access.permission_enum', '');
    app()->forgetInstance(PermissionVocabulary::class);

    expect(fn () => app(PermissionVocabulary::class))->toThrow(RuntimeException::class, 'Set access.permission_enum');
});

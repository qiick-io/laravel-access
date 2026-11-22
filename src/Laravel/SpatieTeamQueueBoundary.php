<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps Spatie's process-local team id isolated across queue jobs without
 * wiping the request team when a sync-connection job nests under a request
 * (same sync/async framing as ActorPayloadStamper).
 *
 * Async workers: clear before and after each job so job 2 never inherits job 1.
 * Sync connection: save the request team, leave it visible during the job, then
 * restore after so a nested switchTo cannot leak into the rest of the request.
 * Organisation-scoped jobs must still call TeamContext::switchTo themselves.
 *
 * @api
 */
final class SpatieTeamQueueBoundary
{
    /** @var list<string|int|null> */
    private array $savedTeamIds = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    public function begin(string $connectionName): void
    {
        if ($this->isAsyncConnection($connectionName)) {
            $this->resetTeamId();

            return;
        }

        $this->savedTeamIds[] = $this->currentTeamId();
    }

    public function end(string $connectionName): void
    {
        if ($this->isAsyncConnection($connectionName)) {
            $this->resetTeamId();

            return;
        }

        if ($this->savedTeamIds === []) {
            $this->resetTeamId();

            return;
        }

        $this->setTeamId(array_pop($this->savedTeamIds));
    }

    public function isAsyncConnection(string $connectionName): bool
    {
        return $connectionName !== 'sync';
    }

    private function currentTeamId(): string|int|null
    {
        if (! $this->app->bound(PermissionRegistrar::class)) {
            return null;
        }

        return $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId();
    }

    private function setTeamId(string|int|null $teamId): void
    {
        if (! $this->app->bound(PermissionRegistrar::class)) {
            return;
        }

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($teamId);
    }

    private function resetTeamId(): void
    {
        $this->setTeamId(null);
    }
}

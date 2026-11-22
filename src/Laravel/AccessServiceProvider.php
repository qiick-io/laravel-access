<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\TeamContext;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Boot checks for required product bindings and Gate::before land in a later
 * PR. This provider merges config, binds the scoped AccessContext and
 * ContainerActorResolver, stamps API actors onto queued jobs, clears
 * context on Octane termination (scoped bindings cover per-request/test reset),
 * resets the Spatie team id at job boundaries, loads the package group
 * migrations when access.migrations.load is true, and binds package-default
 * adapters.
 *
 * Note for the boot-check PR (design §3.3 / AC12): an ActorResolver presence
 * check can never fail as designed — this provider always binds
 * ContainerActorResolver. That PR must assert the product-required bindings
 * that have no package default (RoleCatalog, TwoFactorPolicy,
 * TwoFactorResponder, AuthorizationResponder) and
 * permission.register_permission_check_method === false.
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/access.php', 'access');

        $this->app->scoped(AccessContext::class, AccessContext::class);
        $this->app->singleton(ActorPayloadStamper::class, ActorPayloadStamper::class);

        $this->app->scoped(ActorResolver::class, function (Application $app): ActorResolver {
            return new ContainerActorResolver(
                $app->make(ConfigRepository::class),
                $app->make(AuthFactory::class),
                $app,
            );
        });

        $this->app->singleton(TeamContext::class, NoTeamContext::class);

        $this->app->singleton(GroupRoleSource::class, function (Application $app): GroupRoleSource {
            return new EloquentGroupRoleSource($app->make(DatabaseManager::class));
        });

        $this->app->singleton(PermissionVocabulary::class, function (Application $app): PermissionVocabulary {
            $config = $app->make(ConfigRepository::class);
            $enumClass = $config->get('access.permission_enum');

            if (! is_string($enumClass) || $enumClass === '') {
                throw new RuntimeException(
                    'Set access.permission_enum to your PermissionEnum class, or bind PermissionVocabulary yourself.',
                );
            }

            return new SpatiePermissionVocabulary($enumClass);
        });
    }

    public function boot(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if ($config->get('access.migrations.load', true) === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/access.php' => config_path('access.php'),
            ], 'access-config');
        }

        $this->registerOctaneClears();
        $this->registerQueueActorPropagation();
    }

    private function registerOctaneClears(): void
    {
        $events = $this->app->make('events');

        // String names only — no Illuminate\Foundation imports in src/ (Arch).
        $octaneNamespace = 'Laravel\\Octane\\Events\\';
        foreach (['RequestReceived', 'RequestTerminated', 'TaskTerminated', 'TickTerminated'] as $shortName) {
            $event = $octaneNamespace.$shortName;
            if ($this->eventClassExists($event)) {
                $events->listen($event, static function (): void {
                    AccessContext::clear();
                });
            }
        }
    }

    private function registerQueueActorPropagation(): void
    {
        // Do not call createPayloadUsing(null): that would wipe other packages'
        // hooks (including illuminate:log:context).
        Queue::createPayloadUsing(function (mixed $connection, mixed $queue, array $payload): array {
            return $this->app->make(ActorPayloadStamper::class)->stamp();
        });

        $events = $this->app->make('events');
        $stamper = fn (): ActorPayloadStamper => $this->app->make(ActorPayloadStamper::class);

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($stamper): void {
            $this->resetSpatieTeamId();
            $stamper()->beginJob($event->job->payload(), $event->connectionName);
        });

        $endJob = function (JobProcessed|JobFailed $event) use ($stamper): void {
            $stamper()->endJob($event->connectionName);
            $this->resetSpatieTeamId();
        };

        $events->listen(JobProcessed::class, $endJob);
        $events->listen(JobFailed::class, $endJob);
    }

    /**
     * Clear Spatie's process-local team id so job 2 never inherits job 1's
     * organisation (queued-worker leak).
     */
    private function resetSpatieTeamId(): void
    {
        if (! $this->app->bound(PermissionRegistrar::class)) {
            return;
        }

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    private function eventClassExists(string $event): bool
    {
        return class_exists($event);
    }
}

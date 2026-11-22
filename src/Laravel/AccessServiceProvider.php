<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\GateBefore;
use RuntimeException;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Boots Gate::before via GateBefore, scoped EffectivePermissionResolver,
 * required-binding checks (AC12), and refuses
 * permission.register_permission_check_method === true (R4). Also merges
 * config, binds AccessContext / ContainerActorResolver, stamps API actors onto
 * queued jobs, clears context and resolver caches on Octane / request / job
 * boundaries, isolates Spatie team ids via SpatieTeamQueueBoundary, and loads
 * package group migrations when access.migrations.load is true.
 *
 * @api
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/access.php', 'access');

        $this->app->scoped(AccessContext::class, AccessContext::class);
        $this->app->singleton(ActorPayloadStamper::class, ActorPayloadStamper::class);
        $this->app->singleton(SpatieTeamQueueBoundary::class, SpatieTeamQueueBoundary::class);

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

        $this->app->scoped(EffectivePermissionResolver::class, function (Application $app): EffectivePermissionResolver {
            return new EffectivePermissionResolver(
                $app->make(RoleCatalog::class),
                $app->make(GroupRoleSource::class),
                $app->make(PermissionVocabulary::class),
                $app->make(TeamContext::class),
                $app->make(PermissionRegistrar::class),
            );
        });

        $this->app->singleton(GateBefore::class, function (Application $app): GateBefore {
            return new GateBefore(
                $app->make(ActorResolver::class),
                $app->make(EffectivePermissionResolver::class),
                $app->make(PermissionVocabulary::class),
                $app->make(Gate::class),
            );
        });
    }

    public function boot(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        $this->assertRequiredBindings();
        $this->assertSpatiePermissionCheckMethodDisabled($config);

        if ($config->get('access.migrations.load', true) === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/access.php' => config_path('access.php'),
            ], 'access-config');
        }

        $this->registerGateBefore();
        $this->registerOctaneClears();
        $this->registerRequestClears();
        $this->registerQueueActorPropagation();
        $this->registerPermissionEventClears();
    }

    private function assertRequiredBindings(): void
    {
        $required = [
            ActorResolver::class,
            RoleCatalog::class,
            TwoFactorPolicy::class,
            TwoFactorResponder::class,
            AuthorizationResponder::class,
        ];

        foreach ($required as $abstract) {
            if (! $this->app->bound($abstract)) {
                throw new RuntimeException(
                    "qiick/laravel-access requires [{$abstract}] to be bound before boot (AC12).",
                );
            }
        }
    }

    private function assertSpatiePermissionCheckMethodDisabled(ConfigRepository $config): void
    {
        if ($config->get('permission.register_permission_check_method') === true) {
            throw new RuntimeException(
                'permission.register_permission_check_method must be false; GateBefore replaces Spatie\'s Gate::before (R4).',
            );
        }
    }

    private function registerGateBefore(): void
    {
        $gate = $this->app->make(Gate::class);
        // First-class callable so Laravel's ReflectionFunction accepts it, while
        // getClosureThis() still identifies GateBefore for AC1.
        $gateBefore = $this->app->make(GateBefore::class);
        $gate->before($gateBefore(...));
    }

    private function registerOctaneClears(): void
    {
        $events = $this->app->make('events');

        // String names only — no Illuminate\Foundation imports in src/ (Arch).
        $octaneNamespace = 'Laravel\\Octane\\Events\\';
        foreach (['RequestReceived', 'RequestTerminated', 'TaskTerminated', 'TickTerminated'] as $shortName) {
            $event = $octaneNamespace.$shortName;
            if ($this->eventClassExists($event)) {
                $events->listen($event, function (): void {
                    AccessContext::clear();
                    $this->flushResolver();
                });
            }
        }
    }

    private function registerRequestClears(): void
    {
        // String name — avoid importing Illuminate\Foundation\Http\Events\RequestHandled.
        $events = $this->app->make('events');
        $events->listen('Illuminate\\Foundation\\Http\\Events\\RequestHandled', function (): void {
            $this->flushResolver();
        });
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
        $teamBoundary = fn (): SpatieTeamQueueBoundary => $this->app->make(SpatieTeamQueueBoundary::class);

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($stamper, $teamBoundary): void {
            $this->flushResolver();
            $teamBoundary()->begin($event->connectionName);
            $stamper()->beginJob($event->job->payload(), $event->connectionName);
        });

        $endJob = function (JobProcessed|JobFailed $event) use ($stamper, $teamBoundary): void {
            $stamper()->endJob($event->connectionName);
            $teamBoundary()->end($event->connectionName);
            $this->flushResolver();
        };

        $events->listen(JobProcessed::class, $endJob);
        $events->listen(JobFailed::class, $endJob);
    }

    private function registerPermissionEventClears(): void
    {
        $forget = function (object $event): void {
            $model = $event->model ?? null;
            if ($model instanceof Authenticatable) {
                $this->app->make(EffectivePermissionResolver::class)->forget($model);
            }
        };

        Event::listen(RoleAttachedEvent::class, $forget);
        Event::listen(RoleDetachedEvent::class, $forget);
        Event::listen(PermissionAttachedEvent::class, $forget);
        Event::listen(PermissionDetachedEvent::class, $forget);
    }

    private function flushResolver(): void
    {
        if ($this->app->bound(EffectivePermissionResolver::class)) {
            $this->app->make(EffectivePermissionResolver::class)->flush();
        }
    }

    private function eventClassExists(string $event): bool
    {
        return class_exists($event);
    }
}

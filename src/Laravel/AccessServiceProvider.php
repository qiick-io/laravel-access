<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Http\ClearAccessContextMiddleware;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Boot checks for required product bindings and Gate::before land in a later
 * PR. This provider merges config, binds the scoped AccessContext and
 * ContainerActorResolver, stamps API actors onto queued jobs, and clears
 * context at request start / job boundaries / Octane termination.
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
    public const PAYLOAD_KEY = 'qiick_access';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/access.php', 'access');

        $this->app->scoped(AccessContext::class, AccessContext::class);

        $this->app->scoped(ActorResolver::class, function (Application $app): ActorResolver {
            return new ContainerActorResolver(
                $app->make(ConfigRepository::class),
                $app->make(AuthFactory::class),
                $app,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/access.php' => config_path('access.php'),
            ], 'access-config');
        }

        $this->registerRequestStartClear();
        $this->registerOctaneClears();
        $this->registerQueueActorPropagation();
    }

    private function registerRequestStartClear(): void
    {
        $this->app->booted(function (): void {
            if (! $this->app->bound(HttpKernel::class)) {
                return;
            }

            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(ClearAccessContextMiddleware::class);
            } elseif (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(ClearAccessContextMiddleware::class);
            }
        });
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
        // Clear first so a refreshed Testbench app does not stack duplicate hooks.
        Queue::createPayloadUsing(null);
        Queue::createPayloadUsing(static function (mixed $connection, mixed $queue, array $payload): array {
            $context = AccessContext::current();
            if ($context === null || ! $context->isApiActor()) {
                return [];
            }

            return [
                self::PAYLOAD_KEY => [
                    'kind' => $context->kind->value,
                    'credential_uuid' => $context->credentialUuid,
                ],
            ];
        });

        $events = $this->app->make('events');

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $store = $this->app->make(AccessContext::class);
            $payload = $event->job->payload();
            $actor = $payload[self::PAYLOAD_KEY] ?? null;
            $async = $event->connectionName !== 'sync';

            $context = null;
            if (is_array($actor) && isset($actor['kind']) && is_string($actor['kind'])) {
                $kind = ActorKind::tryFrom($actor['kind']);
                if ($kind !== null && (new ActorContext($kind, null))->isApiActor()) {
                    $uuid = $actor['credential_uuid'] ?? null;
                    $context = new ActorContext(
                        $kind,
                        null,
                        is_string($uuid) ? $uuid : null,
                    );
                }
            }

            $store->beginJob($context, $async);
        });

        $endJob = function (JobProcessed|JobFailed $event): void {
            $async = $event->connectionName !== 'sync';
            $this->app->make(AccessContext::class)->endJob($async);
        };

        $events->listen(JobProcessed::class, $endJob);
        $events->listen(JobFailed::class, $endJob);
    }

    private function eventClassExists(string $event): bool
    {
        return class_exists($event);
    }
}

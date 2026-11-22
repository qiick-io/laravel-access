<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Qiick\Access\Contracts\ActorResolver;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Boot checks for required product bindings and Gate::before land in a later
 * PR. This provider merges config, binds ContainerActorResolver, and clears
 * the actor context at the end of each HTTP request (and Octane boundaries).
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/access.php', 'access');

        $this->app->scoped(ActorResolver::class, function (Application $app): ActorResolver {
            return new ContainerActorResolver(
                $app->make(ConfigRepository::class),
                $app->make(AuthFactory::class),
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

        $events = $this->app->make('events');

        // String names only — no Illuminate\Foundation imports in src/ (Arch).
        $clearEvents = [
            'Illuminate\Foundation\Http\Events\RequestHandled',
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\RequestTerminated',
        ];

        foreach ($clearEvents as $event) {
            if (class_exists($event)) {
                $events->listen($event, static function (): void {
                    AccessContext::clear();
                });
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Feature PRs add Gate::before, contract boot checks, middleware aliases and
 * lifecycle cache clears. The scaffold only merges and publishes config.
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/access.php', 'access');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/access.php' => config_path('access.php'),
            ], 'access-config');
        }
    }
}

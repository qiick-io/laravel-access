<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use RuntimeException;

/**
 * Fail-closed boot assertions for the package (AC12, R4, A1). Extracted from
 * AccessServiceProvider so mutation targets cover the checks without mutating
 * the whole provider (design §11 / N4).
 */
final class AccessBootChecks
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function assert(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        $this->assertRequiredBindings();
        $this->assertSpatiePermissionCheckMethodDisabled($config);
        $this->assertSpatieEventsEnabled($config);
    }

    public function assertRequiredBindings(): void
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

    /**
     * Only boolean false is accepted: Spatie registers its own Gate::before for
     * any truthy value (1, "1", "false", "yes"), and an unset key falls back to
     * Spatie's default of true. Its callback would grant the Gate user's
     * permissions to API actors, the gap GateBefore closes (R4 / AC1 / AC2).
     *
     * @throws RuntimeException when the value is not exactly false
     */
    public function assertSpatiePermissionCheckMethodDisabled(ConfigRepository $config): void
    {
        if ($config->get('permission.register_permission_check_method') !== false) {
            throw new RuntimeException(
                'permission.register_permission_check_method must be false; GateBefore replaces Spatie\'s Gate::before (R4).',
            );
        }
    }

    public function assertSpatieEventsEnabled(ConfigRepository $config): void
    {
        if ($config->get('permission.events_enabled') !== true) {
            throw new RuntimeException(
                'permission.events_enabled must be true; Access clears the resolver on Spatie role/permission events (A1).',
            );
        }
    }
}

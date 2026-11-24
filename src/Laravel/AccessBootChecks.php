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
 *
 * @internal
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
        $this->assertEveryGuardClassified($config);
        $this->assertNoLegacyMigrationsSwitch($config);
    }

    /**
     * access.migrations.load was replaced by access.migrations.groups and
     * access.migrations.principals. A published config that still sets it
     * would silently load the group migration next to the product's own
     * tables (mergeConfigFrom merges shallowly), so boot fails instead.
     *
     * @throws RuntimeException when access.migrations.load is still set
     */
    public function assertNoLegacyMigrationsSwitch(ConfigRepository $config): void
    {
        if ($config->has('access.migrations.load')) {
            throw new RuntimeException(
                'access.migrations.load was replaced by access.migrations.groups and access.migrations.principals; see UPGRADING.md.',
            );
        }
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
        $configuredValue = $config->get('permission.register_permission_check_method');

        if ($configuredValue !== false) {
            // The type tells an env() "0" or "off" (strings) apart from a missing key (null).
            $configuredType = get_debug_type($configuredValue);

            throw new RuntimeException(
                "permission.register_permission_check_method must be boolean false, got {$configuredType}; GateBefore replaces Spatie's Gate::before (R4).",
            );
        }
    }

    /**
     * Every auth guard is either a web guard or an API guard. An unclassified
     * token guard would make its users look like web sessions, so API rules
     * (no super-admin, scopes ∩ owner) would not apply to them (design §6).
     *
     * @throws RuntimeException naming the first unclassified guard
     */
    public function assertEveryGuardClassified(ConfigRepository $config): void
    {
        $guards = $config->get('auth.guards', []);
        $webGuards = $config->get('access.web_guards', []);
        $apiGuards = $config->get('access.api_guards', []);
        $classifiedGuards = [
            ...(is_array($webGuards) ? $webGuards : []),
            ...(is_array($apiGuards) ? $apiGuards : []),
        ];

        foreach (array_keys(is_array($guards) ? $guards : []) as $guard) {
            if (! in_array($guard, $classifiedGuards, true)) {
                throw new RuntimeException(
                    "Auth guard [{$guard}] is in neither access.web_guards nor access.api_guards; classify it so API actors cannot pass as web sessions.",
                );
            }
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

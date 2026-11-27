<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ConfigurationUrlParser;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\LegacyGateBefore;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use RuntimeException;
use Stringable;

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
        $this->assertGateSelection($config);
        $this->assertConnectionNamesMatchTheirKeys($config);
    }

    /**
     * user_group_user.user_id holds ids of the member model's table on the
     * group tables' connection, the default one. A member model on another
     * connection would get no group roles at runtime (EloquentGroupRoleSource
     * fails closed), so the misconfiguration is refused at boot instead of
     * silently emptying every group (issue #27). A member model that is not
     * an Eloquent model gets no group roles either and has no connection to
     * compare, and a product that binds its own GroupRoleSource keeps its
     * own membership store, so neither is checked.
     *
     * It builds the member model, whose boot hooks may need services the
     * product sets up in its own providers, so the package provider runs it
     * once the application has booted, not from assert().
     *
     * @throws RuntimeException naming the key, the model and its connection
     */
    public function assertGroupMemberModelOnTheGroupConnection(ConfigRepository $config): void
    {
        $key = $config->get('access.group_member_model') !== null ? 'access.group_member_model' : 'auth.providers.users.model';
        $memberModel = $config->get($key);

        if (! is_string($memberModel) || ! is_subclass_of($memberModel, Model::class)) {
            return;
        }

        // The alias stays only while nothing rebinds GroupRoleSource; a
        // product factory is never built here (it may need a request).
        if (! $this->app->isAlias(GroupRoleSource::class) || $this->app->getAlias(GroupRoleSource::class) !== EloquentGroupRoleSource::class) {
            return;
        }

        $groupConnectionName = $config->get('database.default');
        $memberConnectionName = (new $memberModel)->getConnectionName() ?? $groupConnectionName;

        if ($memberConnectionName !== $groupConnectionName) {
            $shownConnectionName = is_string($memberConnectionName) ? $memberConnectionName : get_debug_type($memberConnectionName);

            throw new RuntimeException(
                "{$key} [{$memberModel}] uses the connection [{$shownConnectionName}], but the group tables are read from the default connection; group members must use the default connection (see docs/adoption.md).",
            );
        }

        // Same connection, other table: a member id would name another account.
        $memberTable = (new $memberModel)->getTable();

        if ($memberTable !== MigrationSettings::usersTable()) {
            throw new RuntimeException(
                "{$key} [{$memberModel}] uses the table [{$memberTable}], but user_group_user.user_id points at [".MigrationSettings::usersTable().']; set access.users_table or the member model to the same table (see docs/adoption.md).',
            );
        }
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

    /**
     * The design §9 compatibility switches must be real booleans, as for
     * register_permission_check_method: an env string such as "false" would
     * otherwise read as a different gate than the one the product meant. The
     * legacy callback must be bound whenever it decides or is shadowed.
     *
     * @throws RuntimeException naming the non-boolean switch or the missing binding
     */
    public function assertGateSelection(ConfigRepository $config): void
    {
        foreach (['access.use_package_gate' => true, 'access.gate_shadow' => false] as $key => $default) {
            if (! is_bool($config->get($key, $default))) {
                throw new RuntimeException("{$key} must be a boolean (design §9).");
            }
        }

        $isLegacyGateUsed = $config->get('access.use_package_gate', true) === false || $config->get('access.gate_shadow', false) === true;

        if ($isLegacyGateUsed && ! $this->app->bound(LegacyGateBefore::class)) {
            throw new RuntimeException(
                'access.use_package_gate = false or access.gate_shadow = true requires ['.LegacyGateBefore::class.'] to be bound before boot.',
            );
        }
    }

    /**
     * Eloquent stamps a connection's reported name on every model it loads,
     * and the group member check trusts that name. Laravel takes it from the
     * `name` option after merging a URL's query options and the selected
     * read, write or direct variant over the base config. It finds the config
     * with Arr::get(), so a dotted name reaches a literal dotted key or a
     * connection nested inside another. Boot fails on all of these, since
     * each could let another database's users pass for a declared connection:
     * a dotted key, a `name` other than the key at the top level or in a
     * variant, and a `name` anywhere else (a nested connection).
     *
     * @throws RuntimeException naming the first connection that can report another name
     */
    public function assertConnectionNamesMatchTheirKeys(ConfigRepository $config): void
    {
        $connections = $config->get('database.connections', []);

        foreach (is_array($connections) ? $connections : [] as $key => $connection) {
            $key = (string) $key;

            if (str_contains($key, '.')) {
                throw new RuntimeException(
                    "Database connection [{$key}] has a dot in its key; Laravel would also resolve that name inside another connection, so rename it.",
                );
            }

            foreach ($this->connectionConfigurations($connection) as $configuration) {
                $this->assertReportsItsKey($key, $configuration);
            }
        }
    }

    /**
     * The connection's own options and, when it has a URL, the options
     * Laravel parses from it (a Stringable URL included).
     *
     * @return list<array<array-key, mixed>>
     */
    private function connectionConfigurations(mixed $connection): array
    {
        if (is_string($connection) || $connection instanceof Stringable) {
            $connection = ['url' => $connection];
        }

        if (! is_array($connection)) {
            return [];
        }

        $url = $connection['url'] ?? null;

        if (! is_string($url) && ! $url instanceof Stringable) {
            return [$connection];
        }

        return [$connection, (new ConfigurationUrlParser)->parseConfiguration((string) $url)];
    }

    /**
     * @param  array<array-key, mixed>  $configuration
     *
     * @throws RuntimeException when a reported name differs from the key or a name sits outside the variants
     */
    private function assertReportsItsKey(string $key, array $configuration): void
    {
        $reportedNames = array_key_exists('name', $configuration) ? [$configuration['name']] : [];
        $nestedOptions = [];

        foreach ($configuration as $option => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (! in_array($option, ['read', 'write', 'direct'], true)) {
                $nestedOptions[] = $value;

                continue;
            }

            foreach (array_is_list($value) ? $value : [$value] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                foreach ($this->connectionConfigurations($variant) as $variantConfiguration) {
                    if (array_key_exists('name', $variantConfiguration)) {
                        $reportedNames[] = $variantConfiguration['name'];
                    }
                }

                $nestedOptions = [...$nestedOptions, ...array_filter($variant, is_array(...))];
            }
        }

        foreach ($reportedNames as $reportedName) {
            if ($reportedName !== $key) {
                $shownName = is_string($reportedName) ? $reportedName : get_debug_type($reportedName);

                throw new RuntimeException(
                    "Database connection [{$key}] reports the name [{$shownName}]; remove that name so the connection is known by its key (see docs/adoption.md).",
                );
            }
        }

        foreach ($nestedOptions as $nestedOption) {
            if ($this->containsName($nestedOption)) {
                throw new RuntimeException(
                    "Database connection [{$key}] nests a 'name' outside its read, write and direct variants; move that connection to its own key (see docs/adoption.md).",
                );
            }
        }
    }

    /**
     * A `name` at any depth, a URL's query options included: Laravel parses
     * the `url` of whichever nested array a dotted connection name reaches.
     *
     * @param  array<array-key, mixed>  $options
     */
    private function containsName(array $options): bool
    {
        if (array_key_exists('name', $options)) {
            return true;
        }

        $urlOptions = $this->connectionConfigurations($options)[1] ?? null;

        if ($urlOptions !== null && $this->containsName($urlOptions)) {
            return true;
        }

        foreach ($options as $value) {
            if (is_array($value) && $this->containsName($value)) {
                return true;
            }
        }

        return false;
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

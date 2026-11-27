<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Qiick\Access\Laravel\AccessBootChecks;
use Qiick\Access\Tests\Support\ArchiveConnectionUser;
use Qiick\Access\Tests\Support\DefaultConnectionNameUser;
use Qiick\Access\Tests\Support\SpatieUser;

it('fails when register_permission_check_method is true (R4)', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $config->set('permission.register_permission_check_method', true);

    expect(fn () => $checks->assertSpatiePermissionCheckMethodDisabled($config))
        ->toThrow(RuntimeException::class, 'register_permission_check_method');

    $config->set('permission.register_permission_check_method', false);
});

/**
 * Spatie registers its own Gate::before for any truthy value, and an unset key
 * falls back to Spatie's default of true, so only boolean false is safe (R4).
 *
 * @return list<mixed>
 */
dataset('non_false_permission_check_method_values', [
    'integer one' => 1,
    'string one' => '1',
    'string false' => 'false',
    'string zero' => '0',
    'string yes' => 'yes',
    'null' => null,
]);

it('fails when register_permission_check_method is anything but boolean false (R4)', function (mixed $configuredValue): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $config->set('permission.register_permission_check_method', $configuredValue);

    expect(fn () => $checks->assertSpatiePermissionCheckMethodDisabled($config))
        ->toThrow(RuntimeException::class, 'register_permission_check_method must be boolean false, got '.get_debug_type($configuredValue));

    $config->set('permission.register_permission_check_method', false);
})->with('non_false_permission_check_method_values');

it('fails when permission.events_enabled is not true (A1 / N3)', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $config->set('permission.events_enabled', false);

    expect(fn () => $checks->assertSpatieEventsEnabled($config))
        ->toThrow(RuntimeException::class, 'events_enabled');

    $config->set('permission.events_enabled', null);

    expect(fn () => $checks->assertSpatieEventsEnabled($config))
        ->toThrow(RuntimeException::class, 'events_enabled');

    $config->set('permission.events_enabled', true);
});

it('passes when Spatie permission switches are correct', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $config->set('permission.register_permission_check_method', false);
    $config->set('permission.events_enabled', true);

    $checks->assertSpatiePermissionCheckMethodDisabled($config);
    $checks->assertSpatieEventsEnabled($config);

    expect(true)->toBeTrue();
});

/**
 * @return list<class-string>
 */
dataset('required_boot_bindings', [
    ActorResolver::class,
    RoleCatalog::class,
    TwoFactorPolicy::class,
    TwoFactorResponder::class,
    AuthorizationResponder::class,
]);

it('fails when a required product binding is missing (AC12)', function (string $abstract): void {
    $checks = app(AccessBootChecks::class);
    $previous = app()->make($abstract);
    app()->offsetUnset($abstract);

    expect(fn () => $checks->assertRequiredBindings())
        ->toThrow(RuntimeException::class, $abstract);

    app()->instance($abstract, $previous);
})->with('required_boot_bindings');

it('assert() runs every boot check', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);

    $config->set('permission.register_permission_check_method', false);
    $config->set('permission.events_enabled', true);
    $checks->assert();

    $config->set('permission.register_permission_check_method', true);
    expect(fn () => $checks->assert())->toThrow(RuntimeException::class, 'register_permission_check_method');
    $config->set('permission.register_permission_check_method', false);

    $config->set('permission.events_enabled', false);
    expect(fn () => $checks->assert())->toThrow(RuntimeException::class, 'events_enabled');
    $config->set('permission.events_enabled', true);

    $previous = app()->make(RoleCatalog::class);
    app()->offsetUnset(RoleCatalog::class);
    expect(fn () => $checks->assert())->toThrow(RuntimeException::class, RoleCatalog::class);
    app()->instance(RoleCatalog::class, $previous);
});

it('fails when an auth guard is neither a web nor an API guard', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $config->set('auth.guards', ['web' => [], 'token' => [], 'api-key' => []]);
    $config->set('access.web_guards', ['web']);
    $config->set('access.api_guards', ['api-key']);

    expect(fn () => $checks->assertEveryGuardClassified($config))
        ->toThrow(RuntimeException::class, 'Auth guard [token] is in neither');

    expect(fn () => $checks->assert())
        ->toThrow(RuntimeException::class, 'Auth guard [token]');

    $config->set('access.api_guards', ['api-key', 'token']);
    $checks->assertEveryGuardClassified($config);

    $config->set('access.web_guards', 'web');
    expect(fn () => $checks->assertEveryGuardClassified($config))
        ->toThrow(RuntimeException::class, 'Auth guard [web]');

    $config->set('access.web_guards', ['web']);
    $config->set('access.api_guards', 'token');
    expect(fn () => $checks->assertEveryGuardClassified($config))
        ->toThrow(RuntimeException::class, 'Auth guard [token]');

    $config->set('auth.guards', 'web');
    $checks->assertEveryGuardClassified($config);
});

it('fails when the replaced access.migrations.load switch is still configured', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $checks->assertNoLegacyMigrationsSwitch($config);

    $config->set('access.migrations', ['load' => false]);

    expect(fn () => $checks->assertNoLegacyMigrationsSwitch($config))
        ->toThrow(RuntimeException::class, 'access.migrations.load was replaced')
        ->and(fn () => $checks->assert())->toThrow(RuntimeException::class, 'access.migrations.load was replaced');

    $config->set('access.migrations', ['groups' => true, 'principals' => true]);
});

it('fails when a database connection reports a name other than its key', function (Closure $connectionConfig): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $checks->assertConnectionNamesMatchTheirKeys($config);
    $defaultConnection = $config->get('database.default');

    // Eloquent stamps a connection's reported name on the models it loads, and
    // Laravel takes that name from these options (URL query options and the
    // read/write/direct variants are merged over the base config), so a
    // second database could pass its users off as the default's.
    $config->set('database.connections.secondary', $connectionConfig($defaultConnection));

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))
        ->toThrow(RuntimeException::class, "Database connection [secondary] reports the name [{$defaultConnection}]");

    $config->set('database.connections.secondary', null);
})->with([
    'top-level name' => fn (string $name): array => ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite', 'name' => $name],
    'URL query option' => fn (string $name): array => ['url' => "sqlite:////tmp/tenant.sqlite?name={$name}"],
    'URL string' => fn (string $name): string => "sqlite:////tmp/tenant.sqlite?name={$name}",
    'read endpoint of another database' => fn (string $name): array => ['driver' => 'sqlite', 'name' => $name, 'read' => ['database' => '/tmp/tenant.sqlite'], 'write' => ['database' => '/tmp/central.sqlite']],
    'read variant name' => fn (string $name): array => ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite', 'read' => ['name' => $name], 'write' => []],
    'writer name' => fn (string $name): array => ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite', 'read' => [], 'write' => ['name' => $name]],
    'one of several writers' => fn (string $name): array => ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite', 'read' => [], 'write' => [['host' => 'a'], ['host' => 'b', 'name' => $name]]],
    'direct endpoint' => fn (string $name): array => ['driver' => 'pgsql', 'database' => 'tenant', 'direct' => ['name' => $name]],
    'writer inside the URL query' => fn (string $name): array => ['url' => "sqlite:////tmp/tenant.sqlite?read[database]=/tmp/tenant.sqlite&write[database]=/tmp/central.sqlite&write[name]={$name}"],
    'writer list inside a URL string' => fn (string $name): string => "sqlite:////tmp/tenant.sqlite?read[database]=/tmp/tenant.sqlite&write[0][host]=a&write[1][name]={$name}",
    'direct inside the URL query' => fn (string $name): array => ['url' => "pgsql://tenant.internal/tenant?direct[name]={$name}"],
    'top-level name next to a URL' => fn (string $name): array => ['url' => 'sqlite:////tmp/tenant.sqlite', 'name' => $name],
    'writer after a scalar list entry' => fn (string $name): array => ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite', 'read' => [], 'write' => ['ignored', ['name' => $name]]],
    'Stringable URL' => fn (string $name): array => ['url' => Str::of("sqlite:////tmp/tenant.sqlite?name={$name}")],
]);

it('accepts connections that report their own key, including writer and replica lists', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $database = tempnam(sys_get_temp_dir(), 'access-shared-');
    $config->set('database.connections.failover', [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'name' => 'failover',
        'read' => [['host' => 'replica-a'], ['host' => 'replica-b']],
        'write' => [['host' => 'writer-a'], ['host' => 'writer-b', 'name' => 'failover']],
    ]);
    $config->set('database.connections.url', "sqlite:///{$database}?name=url");

    $checks->assertConnectionNamesMatchTheirKeys($config);

    // Two writers of one dataset on different hosts are a supported shape.
    expect(DB::connection('failover')->select('select 1 as one'))->toHaveCount(1);

    DB::purge('failover');
    $config->set('database.connections.failover', null);
    $config->set('database.connections.url', null);
    unlink($database);
});

it('fails when a connection key or a connection nested in another can stand for another name', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $defaultConnection = $config->get('database.default');
    $defaultConfig = $config->get("database.connections.{$defaultConnection}");

    // Laravel resolves DB::connection('tenant.read') through Arr::get(): a
    // literal dotted key, or else a connection nested inside 'tenant'.
    $config->set('database.connections', [...$config->get('database.connections'), 'tenant.read' => ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite']]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))
        ->toThrow(RuntimeException::class, 'Database connection [tenant.read] has a dot in its key');

    $connections = $config->get('database.connections');
    unset($connections['tenant.read']);
    $config->set('database.connections', $connections);
    $config->set("database.connections.{$defaultConnection}.secondary", ['driver' => 'sqlite', 'database' => '/tmp/tenant.sqlite', 'name' => $defaultConnection]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))
        ->toThrow(RuntimeException::class, "Database connection [{$defaultConnection}] nests a 'name' outside its read, write and direct variants");

    // Deeper, and inside a variant: still a connection Laravel could reach.
    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'options' => ['tenants' => ['secondary' => ['name' => $defaultConnection]]]]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))->toThrow(RuntimeException::class, 'nests a');

    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'read' => [], 'write' => ['database' => '/tmp/central.sqlite', 'sticky' => ['name' => $defaultConnection]]]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))->toThrow(RuntimeException::class, 'nests a');

    // A URL nested at any depth carries its name in its query, and Laravel
    // parses it once a dotted name reaches that nested connection.
    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'tenants' => ['secondary' => ['url' => Str::of("sqlite:////tmp/tenant.sqlite?name={$defaultConnection}")]]]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))->toThrow(RuntimeException::class, 'nests a');

    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'tenants' => ['secondary' => ['url' => 'sqlite:////tmp/tenant.sqlite?write[name]=other']]]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))->toThrow(RuntimeException::class, 'nests a');

    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'read' => [], 'write' => ['url' => 'sqlite:////tmp/central.sqlite?name=other']]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))->toThrow(RuntimeException::class, 'reports the name [other]');

    // A nested URL without a name is fine.
    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'tenants' => ['secondary' => ['url' => 'sqlite:////tmp/tenant.sqlite']]]);
    $checks->assertConnectionNamesMatchTheirKeys($config);

    // A non-string name is named by its type; a non-array connection has no name.
    $config->set("database.connections.{$defaultConnection}", [...$defaultConfig, 'name' => 7]);

    expect(fn () => $checks->assertConnectionNamesMatchTheirKeys($config))->toThrow(RuntimeException::class, 'reports the name [int]');

    $config->set("database.connections.{$defaultConnection}", $defaultConfig);
    $config->set('database.connections.unset', 42);
    $checks->assertConnectionNamesMatchTheirKeys($config);
    $config->set('database.connections.unset', null);
});

it('fails when the group member model uses another connection than the group tables (#27)', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $config->set('database.connections.archive', ['driver' => 'sqlite', 'database' => ':memory:']);

    // The configured member model, then the auth provider model as fallback.
    $config->set('access.group_member_model', ArchiveConnectionUser::class);

    expect(fn () => $checks->assertGroupMemberModelOnTheGroupConnection($config))
        ->toThrow(RuntimeException::class, 'access.group_member_model ['.ArchiveConnectionUser::class.'] uses the connection [archive]');

    $config->set('access.group_member_model', null);
    $config->set('auth.providers.users.model', ArchiveConnectionUser::class);

    expect(fn () => $checks->assertGroupMemberModelOnTheGroupConnection($config))
        ->toThrow(RuntimeException::class, 'auth.providers.users.model ['.ArchiveConnectionUser::class.'] uses the connection [archive]');

    // The default connection, left null or named on every instance, boots.
    $config->set('auth.providers.users.model', SpatieUser::class);
    $checks->assertGroupMemberModelOnTheGroupConnection($config);

    $config->set('access.group_member_model', DefaultConnectionNameUser::class);
    $checks->assertGroupMemberModelOnTheGroupConnection($config);

    // No Eloquent member model (a database auth provider, or none) leaves
    // groups empty at runtime and has nothing to compare here.
    $config->set('access.group_member_model', GenericUser::class);
    $checks->assertGroupMemberModelOnTheGroupConnection($config);

    $config->set('access.group_member_model', null);
    $config->set('auth.providers.users.model', null);
    $checks->assertGroupMemberModelOnTheGroupConnection($config);

    $config->set('database.connections.archive', null);
});

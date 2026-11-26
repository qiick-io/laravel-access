<?php

declare(strict_types=1);

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Qiick\Access\Laravel\ConnectionIdentityGuard;

/**
 * One effective connection name, one database: a name that would stand for
 * another database (another config, a repointed alias, a reconnect after the
 * config changed) fails before any query runs, so Eloquent never stamps it on
 * a model loaded from that other database.
 */
it('fails closed when a connection name is repointed at another database', function (): void {
    $firstDatabase = tempnam(sys_get_temp_dir(), 'access-first-');
    $secondDatabase = tempnam(sys_get_temp_dir(), 'access-second-');
    config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $firstDatabase, 'prefix' => '']);
    DB::connection('tenant')->select('select 1');

    // A lost connection reconnects to the same database.
    DB::reconnect('tenant');
    DB::connection('tenant')->select('select 1');

    // Same alias, another database: after a purge, or a bare reconnect.
    config()->set('database.connections.tenant.database', $secondDatabase);

    expect(fn () => DB::reconnect('tenant'))->toThrow(RuntimeException::class, 'Database connection [tenant]')
        ->and(fn () => DB::connection('tenant')->select('select 1'))->toThrow(RuntimeException::class);

    DB::purge('tenant');

    expect(fn () => DB::connection('tenant'))->toThrow(RuntimeException::class)
        ->and(fn () => DB::connection('tenant'))->toThrow(RuntimeException::class);

    DB::purge('tenant');
    unlink($firstDatabase);
    unlink($secondDatabase);
});

it('rejects writer variants that are different databases', function (): void {
    $firstDatabase = tempnam(sys_get_temp_dir(), 'access-first-');
    $secondDatabase = tempnam(sys_get_temp_dir(), 'access-second-');
    config()->set('database.connections.tenant', [
        'driver' => 'sqlite',
        'prefix' => '',
        'read' => ['database' => $firstDatabase],
        'write' => [['database' => $firstDatabase], ['database' => $secondDatabase]],
    ]);

    expect(fn () => DB::connection('tenant'))->toThrow(RuntimeException::class, 'Database connection [tenant]');

    DB::purge('tenant');
    unlink($firstDatabase);
    unlink($secondDatabase);
});

it('lets two names of the same database coexist', function (): void {
    config()->set('database.connections.replica', config('database.connections.'.config('database.default')));

    expect(DB::connection('replica')->getName())->toBe('replica')
        ->and(DB::connection()->getName())->toBe(config('database.default'));
});

it('rejects another database configured under the default connection name', function (): void {
    $defaultConnection = config('database.default');
    $tenantDatabase = tempnam(sys_get_temp_dir(), 'access-tenant-');
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => $tenantDatabase, 'prefix' => '', 'name' => $defaultConnection]);

    expect(fn () => DB::connection('secondary'))->toThrow(RuntimeException::class, "Database connection [{$defaultConnection}]")
        ->and(DB::connection()->getName())->toBe($defaultConnection);

    config()->set('database.connections.secondary', null);
    unlink($tenantDatabase);
});

it('accepts read/write connections whose writers are the same database', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'access-shared-');
    config()->set('database.connections.split', ['driver' => 'sqlite', 'prefix' => '', 'read' => ['database' => $database], 'write' => ['database' => $database]]);
    config()->set('database.connections.pooled', ['driver' => 'sqlite', 'prefix' => '', 'read' => ['database' => $database], 'write' => [['database' => $database], ['database' => $database]]]);
    config()->set('database.connections.single', ['driver' => 'sqlite', 'prefix' => '', 'read' => ['database' => $database], 'write' => [['database' => $database]]]);
    config()->set('database.connections.url', "sqlite:///{$database}");

    expect(DB::connection('split')->select('select 1 as one'))->toHaveCount(1)
        ->and(DB::connection('pooled')->select('select 1 as one'))->toHaveCount(1)
        ->and(DB::connection('single')->select('select 1 as one'))->toHaveCount(1)
        ->and(DB::connection('url')->select('select 1 as one'))->toHaveCount(1);

    // The writer's own options count: another database there fails.
    DB::purge('split');
    config()->set('database.connections.split.write', ['database' => $database, 'prefix' => 'other_']);

    expect(fn () => DB::connection('split'))->toThrow(RuntimeException::class);

    DB::purge('split');
    DB::purge('pooled');
    DB::purge('single');
    DB::purge('url');
    unlink($database);
});

it('rejects a database reporting the name of a read/write connection that was never opened', function (): void {
    $centralDatabase = tempnam(sys_get_temp_dir(), 'access-central-');
    $tenantDatabase = tempnam(sys_get_temp_dir(), 'access-tenant-');
    config()->set('database.connections.central', ['driver' => 'sqlite', 'database' => $centralDatabase, 'prefix' => '', 'read' => ['database' => $centralDatabase], 'write' => ['prefix' => '']]);
    config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $tenantDatabase, 'prefix' => '', 'name' => 'central']);

    expect(fn () => DB::connection('tenant'))->toThrow(RuntimeException::class, 'Database connection [central]')
        ->and(DB::connection('central')->getName())->toBe('central');

    DB::purge('central');
    unlink($centralDatabase);
    unlink($tenantDatabase);
});

it('tells databases apart by every identity option', function (string $option, mixed $first, mixed $second): void {
    DB::connectUsing('dynamic', ['driver' => 'sqlite', 'database' => ':memory:', $option => $first]);
    DB::connectUsing('dynamic', ['driver' => 'sqlite', 'database' => ':memory:', $option => $first], force: true);

    expect(fn () => DB::connectUsing('dynamic', ['driver' => 'sqlite', 'database' => ':memory:', $option => $second], force: true))
        ->toThrow(RuntimeException::class, 'Database connection [dynamic]');
})->with([
    'driver' => ['driver', 'sqlite', 'mysql'],
    'host' => ['host', 'central.internal', 'tenant.internal'],
    'port' => ['port', 5432, 5433],
    'socket' => ['unix_socket', '/run/central.sock', '/run/tenant.sock'],
    'database' => ['database', ':memory:', '/tmp/access-tenant.sqlite'],
    'prefix' => ['prefix', '', 'tenant_'],
    'search path' => ['search_path', 'central', 'tenant'],
    'schema' => ['schema', 'central', 'tenant'],
]);

it('refuses a connection that carries no name', function (): void {
    $unnamedConnection = new SQLiteConnection(fn (): PDO => new PDO('sqlite::memory:'), ':memory:', '', []);

    expect(fn () => app(ConnectionIdentityGuard::class)->assertUnambiguous($unnamedConnection))
        ->toThrow(RuntimeException::class, 'without a name');
});

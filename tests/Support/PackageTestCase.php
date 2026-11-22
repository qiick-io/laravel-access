<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Qiick\Access\Laravel\AccessServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Boots a Testbench Laravel app with the package provider. CI runs the suite
 * on Laravel 12 and 13, and on SQLite / PostgreSQL / MySQL / MariaDB.
 */
abstract class PackageTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            AccessServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('access.permission_enum', FakePermissionEnum::class);
        $app['config']->set('permission.register_permission_check_method', false);

        $app->instance(RoleCatalog::class, new FakeRoleCatalog);
        $app->instance(TwoFactorPolicy::class, new FakeTwoFactorPolicy);
        $app->instance(TwoFactorResponder::class, new FakeTwoFactorResponder);
        $app->instance(AuthorizationResponder::class, new FakeAuthorizationResponder);

        $connection = getenv('DB_CONNECTION') ?: 'testing';
        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => getenv('DB_DATABASE') ?: ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        if (in_array($connection, ['mysql', 'mariadb', 'pgsql'], true)) {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => $connection === 'mariadb' ? 'mariadb' : $connection,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('DB_DATABASE') ?: 'laravel_access',
                'username' => getenv('DB_USERNAME') ?: ($connection === 'pgsql' ? 'postgres' : 'root'),
                'password' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
                'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                'strict' => true,
            ]);
        }
    }
}

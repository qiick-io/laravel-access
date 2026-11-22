<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Feature;

use Qiick\Access\Tests\Support\PackageTestCase;

/**
 * Boots with access.migrations.load = false (externa adoption path).
 */
final class MigrationsLoadDisabledPhpUnitTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('access.migrations.load', false);
    }

    public function test_it_skips_package_migration_paths_when_load_is_false(): void
    {
        $packageMigrations = realpath(dirname(__DIR__, 2).'/database/migrations');
        $this->assertNotFalse($packageMigrations);
        $this->assertFalse((bool) config('access.migrations.load'));

        $loaded = [];
        foreach (app('migrator')->paths() as $path) {
            $resolved = realpath($path);
            if (is_string($resolved) && $resolved !== '') {
                $loaded[] = $resolved;
            }
        }

        $this->assertNotContains($packageMigrations, $loaded);
    }
}

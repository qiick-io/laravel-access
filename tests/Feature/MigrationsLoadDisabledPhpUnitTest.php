<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Feature;

use Qiick\Access\Tests\Support\PackageTestCase;

/**
 * Boots with access.migrations.groups = false (externa adoption path): the
 * group migrations are skipped, the principal migrations still load.
 */
final class MigrationsLoadDisabledPhpUnitTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('access.migrations.groups', false);
    }

    public function test_it_skips_only_the_disabled_migration_set(): void
    {
        $loaded = [];
        foreach (app('migrator')->paths() as $path) {
            $resolved = realpath($path);
            if (is_string($resolved) && $resolved !== '') {
                $loaded[] = $resolved;
            }
        }

        $this->assertNotContains(realpath(dirname(__DIR__, 2).'/database/migrations/groups'), $loaded);
        $this->assertContains(realpath(dirname(__DIR__, 2).'/database/migrations/principals'), $loaded);
    }
}

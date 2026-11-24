<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Feature;

use Qiick\Access\Tests\Support\PackageTestCase;

/**
 * Boots with access.migrations.principals = false: only that set is skipped.
 */
final class PrincipalMigrationsDisabledPhpUnitTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('access.migrations.principals', false);
    }

    public function test_it_skips_only_the_principal_migrations(): void
    {
        $loaded = array_map(static fn (string $path): string|false => realpath($path), app('migrator')->paths());

        $this->assertNotContains(realpath(dirname(__DIR__, 2).'/database/migrations/principals'), $loaded);
        $this->assertContains(realpath(dirname(__DIR__, 2).'/database/migrations/groups'), $loaded);
    }
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Tests\Support\ArchiveConnectionUser;
use Qiick\Access\Tests\Support\PackageTestCase;
use RuntimeException;

/**
 * The real bootstrap refuses a group member model on another connection than
 * the group tables (issue #27), after every provider has booted, and leaves a
 * product with its own GroupRoleSource alone.
 */
final class GroupMemberConnectionBootPhpUnitTest extends PackageTestCase
{
    private bool $isOwnGroupRoleSourceBound = false;

    protected function setUp(): void
    {
        // Each test boots the application itself, after choosing its setup.
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            parent::tearDown();
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.archive', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('access.group_member_model', ArchiveConnectionUser::class);

        if ($this->isOwnGroupRoleSourceBound) {
            $app->instance(GroupRoleSource::class, new class implements GroupRoleSource
            {
                public function roleIdsFor(Authenticatable $user): array
                {
                    return [];
                }

                public function instanceRoleIdsFor(Authenticatable $user): array
                {
                    return [];
                }
            });
        }
    }

    public function test_boot_fails_once_the_application_has_booted(): void
    {
        // The fixture's boot hook throws a LogicException when the check
        // builds it before every provider has booted.
        Model::clearBootedModels();

        try {
            parent::setUp();
            $this->fail('Boot accepted a member model on the archive connection.');
        } catch (RuntimeException $exception) {
            // The failed bootstrap left its error handlers installed.
            HandleExceptions::flushState($this);
            $this->assertStringContainsString('access.group_member_model ['.ArchiveConnectionUser::class.'] uses the connection [archive]', $exception->getMessage());
        }
    }

    public function test_a_product_group_role_source_keeps_its_own_membership_store(): void
    {
        $this->isOwnGroupRoleSourceBound = true;
        parent::setUp();

        $this->assertTrue($this->app->isBooted());
    }
}

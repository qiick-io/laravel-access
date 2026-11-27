<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Feature;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Qiick\Access\Principals\Principal;
use Qiick\Access\Tests\Support\PackageTestCase;
use RuntimeException;

/**
 * F1 through the real bootstrap: an older published config/access.php
 * (loaded before the package provider registers) gets the nested defaults,
 * and an invalid value in it stops the boot with its key.
 */
final class PublishedConfigBootPhpUnitTest extends PackageTestCase
{
    /** @var array<string, mixed> */
    private array $publishedConfig = [
        'migrations' => ['groups' => false],
        'principals' => ['types' => ['user', 'service']],
        'two_factor' => [],
    ];

    protected function setUp(): void
    {
        // Each test boots the application itself, after choosing its config.
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            parent::tearDown();
        }
    }

    /**
     * Where Laravel loads config/*.php, before any provider registers: the
     * published file as the package provider finds it.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('access', $this->publishedConfig);
    }

    public function test_an_older_published_config_boots_with_the_nested_defaults(): void
    {
        parent::setUp();

        $this->assertSame(['groups' => false, 'principals' => true], config('access.migrations'));
        $this->assertSame(Principal::class, config('access.principals.model'));
        $this->assertSame(['user', 'service'], config('access.principals.types'));
        $this->assertContains('logout', config('access.two_factor.exempt_route_names'));
    }

    public function test_an_invalid_published_value_stops_the_boot_with_its_key(): void
    {
        $this->publishedConfig['migrations'] = ['groups' => '1'];

        try {
            parent::setUp();
            $this->fail('Boot accepted access.migrations.groups = "1".');
        } catch (RuntimeException $exception) {
            // The failed bootstrap left its error handlers installed.
            HandleExceptions::flushState($this);
            $this->assertSame('access.migrations.groups must be a boolean.', $exception->getMessage());
        }
    }
}

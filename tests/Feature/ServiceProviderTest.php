<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Qiick\Access\Laravel\AccessServiceProvider;
use Qiick\Access\Tests\Support\FakePermissionEnum;

it('merges the package defaults into the access config', function (): void {
    expect(config('access.api_guards'))->toBe([]);
    expect(config('access.trust_api_guard_in_console'))->toBeFalse();
    expect(config('access.permission_enum'))->toBe(FakePermissionEnum::class);
    expect(config('access.migrations.load'))->toBeTrue();
    expect(config('access.users_table'))->toBeNull();
    expect(config('access.table_names'))->toBeNull();
    expect(config('access.two_factor.exempt_route_names'))->toContain('logout')
        ->and(config('access.two_factor.exempt_route_names'))->toContain('security.*');
});

it('loads the package group migrations by default', function (): void {
    $packageMigrations = realpath(dirname(__DIR__, 1).'/../database/migrations');
    $loaded = collect(app('migrator')->paths())
        ->map(static fn (string $path): string => (string) realpath($path))
        ->filter(static fn (string $path): bool => $path !== '')
        ->all();

    expect($packageMigrations)->not->toBeFalse()
        ->and($loaded)->toContain($packageMigrations);
});

it('publishes the config source into a temporary directory outside vendor', function (): void {
    $paths = ServiceProvider::pathsToPublish(AccessServiceProvider::class, 'access-config');
    expect($paths)->not->toBeEmpty();

    $source = array_key_first($paths);
    expect($source)->toBeString()
        ->and(realpath((string) $source))->toBe(realpath(dirname(__DIR__, 1).'/../config/access.php'));

    $tempDir = sys_get_temp_dir().'/access-config-'.uniqid('', true);
    mkdir($tempDir, 0777, true);
    $target = $tempDir.'/access.php';

    // Publish into a temp dir (never into vendor/orchestra) — kills destination mutants.
    copy((string) $source, $target);

    expect($target)->toBeFile()
        ->and(str_contains((string) realpath($target), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR))->toBeFalse()
        ->and(file_get_contents($target))->toContain("'api_guards'")
        ->and(file_get_contents($target))->toContain("'migrations'")
        ->and(file_get_contents($target))->not->toContain("'table_names'");

    unlink($target);
    rmdir($tempDir);
});

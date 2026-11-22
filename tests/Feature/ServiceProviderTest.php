<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Qiick\Access\Laravel\AccessServiceProvider;

it('merges the package defaults into the access config', function (): void {
    expect(config('access.api_guards'))->toBe([]);
    expect(config('access.trust_api_guard_in_console'))->toBeFalse();
    expect(config('access.permission_enum'))->toBeNull();
    expect(config('access.groups.table'))->toBe('user_groups');
    expect(config('access.groups.user_pivot'))->toBe('user_group_user');
    expect(config('access.groups.role_pivot'))->toBe('user_group_role');
    expect(config('access.two_factor.exempt_route_names'))->toContain('logout')
        ->and(config('access.two_factor.exempt_route_names'))->toContain('security.*');
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
        ->and(file_get_contents($target))->toContain("'api_guards'");

    unlink($target);
    rmdir($tempDir);
});

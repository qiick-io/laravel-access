<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Qiick\Access\Laravel\AccessBootChecks;

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
        ->toThrow(RuntimeException::class, 'register_permission_check_method');

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

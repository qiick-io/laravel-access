<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Laravel\AccessServiceProvider;

it('fails when register_permission_check_method is true (R4)', function (): void {
    $provider = app()->getProvider(AccessServiceProvider::class);
    expect($provider)->toBeInstanceOf(AccessServiceProvider::class);

    $config = app(ConfigRepository::class);
    $config->set('permission.register_permission_check_method', true);

    $method = new ReflectionMethod(AccessServiceProvider::class, 'assertSpatiePermissionCheckMethodDisabled');

    expect(fn () => $method->invoke($provider, $config))
        ->toThrow(RuntimeException::class, 'register_permission_check_method');

    $config->set('permission.register_permission_check_method', false);
});

it('fails when a required product binding is missing (AC12)', function (): void {
    $provider = app()->getProvider(AccessServiceProvider::class);
    expect($provider)->toBeInstanceOf(AccessServiceProvider::class);

    $previous = app()->make(RoleCatalog::class);
    app()->offsetUnset(RoleCatalog::class);

    $method = new ReflectionMethod(AccessServiceProvider::class, 'assertRequiredBindings');

    expect(fn () => $method->invoke($provider))
        ->toThrow(RuntimeException::class, RoleCatalog::class);

    app()->instance(RoleCatalog::class, $previous);
});

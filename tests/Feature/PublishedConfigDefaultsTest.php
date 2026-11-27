<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Access\Laravel\AccessBootChecks;
use Qiick\Access\Laravel\AccessServiceProvider;
use Qiick\Access\Principals\Principal;

/*
 * F1: a product that published config/access.php before a nested key existed
 * still gets the package default for it (mergeConfigFrom() merges only the
 * top level), and a present but invalid value stops the boot with its key.
 */

it('fills nested keys missing from an older published config with the package defaults (F1)', function (): void {
    config()->set('access', [
        'web_guards' => ['web', 'filament'],
        'migrations' => ['groups' => false],
        'principals' => ['types' => ['user']],
        'two_factor' => [],
    ]);

    (new AccessServiceProvider(app()))->register();

    expect(config('access.migrations'))->toBe(['groups' => false, 'principals' => true])
        ->and(config('access.principals.model'))->toBe(Principal::class)
        ->and(config('access.principals.organisation_required'))->toBeFalse()
        ->and(config('access.principals.instance_types'))->toBe([])
        ->and(config('access.two_factor.exempt_route_names'))->toContain('logout', 'two-factor.*')
        // A list the product set is its own: never merged with the default by index.
        ->and(config('access.principals.types'))->toBe(['user'])
        ->and(config('access.web_guards'))->toBe(['web', 'filament'])
        ->and(config('access.gate_shadow'))->toBeFalse();
});

/**
 * @return array<string, array{string, mixed, string, 3?: class-string<Throwable>}>
 */
dataset('invalid_access_config_values', [
    'api guards not a list' => ['access.api_guards', 'api', 'access.api_guards must be a list of strings'],
    'web guard not a string' => ['access.web_guards', ['web', 7], 'access.web_guards must be a list of strings'],
    'string boolean' => ['access.trust_api_guard_in_console', 'false', 'access.trust_api_guard_in_console must be a boolean'],
    'groups switch as 1' => ['access.migrations.groups', 1, 'access.migrations.groups must be a boolean'],
    'principals switch as string' => ['access.migrations.principals', 'true', 'access.migrations.principals must be a boolean'],
    'organisation required as string' => ['access.principals.organisation_required', '1', 'access.principals.organisation_required must be a boolean'],
    'types empty' => ['access.principals.types', [], 'access.principals.types must be a non-empty list of strings'],
    'instance types map' => ['access.principals.instance_types', ['a' => 'x'], 'access.principals.instance_types must be a list of principal types', InvalidArgumentException::class],
    'instance type upper case' => ['access.principals.instance_types', ['Service'], 'access.principals.instance_types entries must be lower-case', InvalidArgumentException::class],
    'instance types without organisations' => ['access.principals.instance_types', ['service'], 'needs access.principals.organisation_required set to true', InvalidArgumentException::class],
    'exempt routes string' => ['access.two_factor.exempt_route_names', 'logout', 'access.two_factor.exempt_route_names must be a list of strings'],
    'users table int' => ['access.users_table', 5, 'access.users_table must be null or a non-empty string'],
    'users table empty' => ['access.users_table', '', 'access.users_table must be null or a non-empty string'],
    'member model array' => ['access.group_member_model', ['x'], 'access.group_member_model must be null or a non-empty string'],
    'log channel bool' => ['access.gate_shadow_log_channel', true, 'access.gate_shadow_log_channel must be null or a non-empty string'],
    'permission enum not an enum' => ['access.permission_enum', stdClass::class, 'access.permission_enum must be a backed enum class name', InvalidArgumentException::class],
    'permission enum interface' => ['access.permission_enum', BackedEnum::class, 'access.permission_enum must be a backed enum class name', InvalidArgumentException::class],
    'permission enum int' => ['access.permission_enum', 3, 'access.permission_enum must be null or a backed enum class'],
    'principal model not a principal' => ['access.principals.model', stdClass::class, 'access.principals.model must be '.Principal::class.' or a subclass'],
]);

it('stops the boot on a present but invalid access config value, naming its key (F1)', function (string $key, mixed $value, string $message, string $exceptionClass = RuntimeException::class): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $previous = $config->get($key);
    $config->set($key, $value);

    expect(fn () => $checks->assertConfigValues($config))->toThrow($exceptionClass, $message)
        ->and(fn () => $checks->assert())->toThrow($exceptionClass, $message);

    $config->set($key, $previous);
})->with('invalid_access_config_values');

it('refuses a published config or section that is not a map, naming its key (F1)', function (): void {
    foreach ([[false, 'access must be a map of settings, got bool.'], [['two_factor' => ['logout']], 'access.two_factor must be a map of settings, got array.'], [['principals' => 'user'], 'access.principals must be a map of settings, got string.']] as [$published, $message]) {
        config()->set('access', $published);

        expect(fn () => (new AccessServiceProvider(app()))->register())->toThrow(RuntimeException::class, $message);
    }

    config()->set('access', []);
    (new AccessServiceProvider(app()))->register();

    expect(config('access.principals.model'))->toBe(Principal::class);
});

it('fills missing nested keys on a configuration cached before the upgrade (F1)', function (): void {
    app()->instance('config_loaded_from_cache', true);

    try {
        expect(app()->configurationIsCached())->toBeTrue();

        config()->set('access', ['principals' => ['types' => ['user']]]);
        (new AccessServiceProvider(app()))->register();

        expect(config('access.principals.model'))->toBe(Principal::class)
            ->and(config('access.principals.types'))->toBe(['user']);
    } finally {
        app()->instance('config_loaded_from_cache', false);
    }
});

it('accepts the shipped defaults and the documented optional values (F1)', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);

    $checks->assertConfigValues($config);

    // ACCESS_PERMISSION_ENUM= in .env reads as an empty string: unset.
    $config->set('access.permission_enum', '');
    $config->set('access.users_table', 'members');
    $config->set('access.gate_shadow_log_channel', 'stack');
    $config->set('access.principals.model', (new class extends Principal {})::class);
    $config->set('access.principals.organisation_required', true);
    $config->set('access.principals.instance_types', ['instance_service']);
    $checks->assertConfigValues($config);

    $config->set('access.principals.organisation_required', false);
    $config->set('access.principals.instance_types', []);

    $config->set('access.permission_enum', null);
    $checks->assertConfigValues($config);

    expect(true)->toBeTrue();
});

<?php

declare(strict_types=1);

/*
 * Package defaults for qiick/laravel-access. Products publish this file and bind
 * the required contracts (RoleCatalog, TwoFactorPolicy, TwoFactorResponder,
 * AuthorizationResponder). See docs/reference-contracts.md.
 *
 * Spatie's `permission.register_permission_check_method` must be false; the
 * package provider fails at boot otherwise (design R4 / AC1).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | API guards
    |--------------------------------------------------------------------------
    |
    | Guard names that mark the current actor as an API actor when that guard
    | is the default. Either this signal or a bound ActorContext of kind
    | ApiKey / OAuthGrant / PublicApi wins (design §3.3). Leave empty when the
    | product never calls Auth::shouldUse() for keys (externa REST).
    |
    */

    'api_guards' => array_values(array_filter(array_map(
        static fn (string $guard): string => trim($guard),
        explode(',', (string) env('ACCESS_API_GUARDS', '')),
    ), static fn (string $guard): bool => $guard !== '')),

    /*
    |--------------------------------------------------------------------------
    | Trust API guard in console (testing only)
    |--------------------------------------------------------------------------
    |
    | Under PHPUnit, runningInConsole() is always true, so the API-guard signal
    | is ignored by default (jobs/commands stay unattended). Product feature
    | tests that need the guard path must enable this via
    | Qiick\Access\Testing\TrustApiGuardInConsole::enable() and disable() in
    | tearDown. ContainerActorResolver honours the flag only when
    | app()->environment('testing') is true — never a production lever.
    |
    */

    'trust_api_guard_in_console' => false,

    /*
    |--------------------------------------------------------------------------
    | Permission vocabulary
    |--------------------------------------------------------------------------
    |
    | Fully-qualified backed-enum class that lists every permission name.
    | SpatiePermissionVocabulary reads it when the product binds that default.
    |
    */

    'permission_enum' => env('ACCESS_PERMISSION_ENUM'),

    /*
    |--------------------------------------------------------------------------
    | Package migrations
    |--------------------------------------------------------------------------
    |
    | When true (default), AccessServiceProvider loads the fixed group tables
    | (user_groups / user_group_user / user_group_role). externa 1.x sets this
    | to false and adopts the same table shape through its own additive
    | migration so the released tables keep the standard names until v2 (Q5).
    |
    */

    'migrations' => [
        'load' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor exemption routes
    |--------------------------------------------------------------------------
    |
    | Named routes skipped by EnsureTwoFactorIsEnabled. This is the only grace
    | (design R9). Products may append; they should not remove the defaults
    | without a dated decision.
    |
    */

    'two_factor' => [
        'exempt_route_names' => [
            'logout',
            'security.*',
            'two-factor.*',
            'passkey.*',
            'password.confirm*',
        ],
    ],

];

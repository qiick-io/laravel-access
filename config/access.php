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
    | Group role tables
    |--------------------------------------------------------------------------
    |
    | Fleet table names for EloquentGroupRoleSource. Soft-deleted groups are
    | excluded with whereNull(deleted_at) on the groups table (C7).
    |
    */

    'groups' => [
        'table' => env('ACCESS_GROUPS_TABLE', 'user_groups'),
        'user_pivot' => env('ACCESS_GROUP_USER_PIVOT', 'user_group_user'),
        'role_pivot' => env('ACCESS_GROUP_ROLE_PIVOT', 'user_group_role'),
        'user_foreign_key' => env('ACCESS_GROUP_USER_FOREIGN_KEY', 'user_id'),
        'group_foreign_key' => env('ACCESS_GROUP_FOREIGN_KEY', 'user_group_id'),
        'role_foreign_key' => env('ACCESS_GROUP_ROLE_FOREIGN_KEY', 'role_id'),
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

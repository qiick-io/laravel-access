<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * Written once by the sync, never overwritten or checked: it is this repo's own.
 * Package-only rules (Laravel namespace, no Foundation in src/, no App\, @api
 * surface) are in `PackageArchitectureTest.php`.
 *
 * Public API names follow the binding design (not the shared Contract/Enum suffix
 * table): design-mandated types are listed under ignoring with reasons as they land.
 */
return [
    'namespace' => 'Qiick\Access',

    'ignoring' => [
        'Contracts' => [
            // Design §2.2: names without Contract suffix; ActorKind/ActorContext are
            // not interfaces but live under Contracts/ as the design lays out.
            'Qiick\Access\Contracts\ActorKind',
            'Qiick\Access\Contracts\ActorContext',
            'Qiick\Access\Contracts\ActorResolver',
            'Qiick\Access\Contracts\PermissionVocabulary',
            'Qiick\Access\Contracts\RoleCatalog',
            'Qiick\Access\Contracts\GroupRoleSource',
            'Qiick\Access\Contracts\TeamContext',
            'Qiick\Access\Contracts\TwoFactorPolicy',
            'Qiick\Access\Contracts\TwoFactorResponder',
            'Qiick\Access\Contracts\AuthorizationResponder',
            // Completeness review §3.5 / A7: ActiveUsers has no Contract suffix.
            'Qiick\Access\Contracts\ActiveUsers',
            // Same naming as the §2.2 contracts: API-actor scope seam behind
            // EnsureUserHasPermission (fleet rule: scopes ∩ owner, never super-admin).
            'Qiick\Access\Contracts\ApiActorScopeCheck',
            // Design §9.2 step 6: the externa legacy callback seam, removed in v2.
            'Qiick\Access\Contracts\LegacyGateBefore',
        ],
        'traits' => [
            // Design §2.1 / §13.1: the FormRequest trait is AuthorizesWithPermission.
            'Qiick\Access\Http\Concerns\AuthorizesWithPermission',
        ],
        'Enums' => [
            // Design §2.2: ActorKind (not *Enum); kept for Enums-namespace rule.
            'Qiick\Access\Contracts\ActorKind',
            // Design §15.4: the public API name is DeactivationReason.
            'Qiick\Access\Principals\DeactivationReason',
        ],
    ],

    'suffixes' => [
        // Design §13.1 places many adapters under Laravel/; only the provider
        // keeps the ServiceProvider suffix (enforced in PackageArchitectureTest).
    ],

    'controllerPublicMethods' => [],
];

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
        ],
        'Enums' => [
            // Design §2.2: ActorKind (not *Enum); kept for Enums-namespace rule.
            'Qiick\Access\Contracts\ActorKind',
        ],
    ],

    'suffixes' => [
        'Laravel' => 'ServiceProvider',
    ],

    'controllerPublicMethods' => [],
];

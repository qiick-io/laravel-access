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
            // Design §2.2: public contract names without Contract suffix.
            // Entries land with each contracts PR.
        ],
        'Enums' => [
            // Design §2.2: ActorKind (not *Enum).
            // Entries land with the ActorKind PR.
        ],
    ],

    'suffixes' => [
        'Laravel' => 'ServiceProvider',
    ],

    'controllerPublicMethods' => [],
];

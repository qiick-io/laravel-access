<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

/**
 * Dispatched after PrincipalDirectory::deactivate() commits (design §15.4).
 * Products revoke the API keys of the principal and of every owned principal
 * in a listener (qiick/laravel-api-keys reason `owner`); the two packages stay
 * independent.
 *
 * @api
 */
final class PrincipalDeactivated
{
    /**
     * @param  list<string>  $ownedIds  every principal owned directly or transitively
     */
    public function __construct(
        public readonly Principal $principal,
        public readonly DeactivationReason $reason,
        public readonly array $ownedIds,
    ) {}
}

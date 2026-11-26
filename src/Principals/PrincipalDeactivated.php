<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after PrincipalDirectory::deactivate(), markErased() or eraseUser() commits,
 * including any transaction the caller wraps them in (design §15.4).
 * Products revoke the API keys of the principal and of every owned principal
 * in a listener (qiick/laravel-api-keys reason `owner`); the two packages stay
 * independent.
 *
 * @api
 */
final class PrincipalDeactivated implements ShouldDispatchAfterCommit
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

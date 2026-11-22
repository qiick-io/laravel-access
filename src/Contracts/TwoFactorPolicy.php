<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Product rules for whether 2FA is required and satisfied.
 *
 * @api
 */
interface TwoFactorPolicy
{
    public function isRequired(Authenticatable $user): bool;

    /** Confirmed TOTP or at least one passkey. */
    public function hasUsableFactor(Authenticatable $user): bool;

    public function isAnyMethodEnabled(): bool;
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Access\Contracts\TwoFactorPolicy;

/**
 * TwoFactorPolicy stub so AccessServiceProvider boot checks pass in Testbench.
 */
final class FakeTwoFactorPolicy implements TwoFactorPolicy
{
    public function isRequired(Authenticatable $user): bool
    {
        return false;
    }

    public function hasUsableFactor(Authenticatable $user): bool
    {
        return true;
    }

    public function isAnyMethodEnabled(): bool
    {
        return true;
    }
}

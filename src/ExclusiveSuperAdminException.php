<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;

/**
 * A write would leave a user with super-admin next to another role
 * (decision 2026-11-26). See ExclusiveSuperAdmin.
 *
 * @api
 */
final class ExclusiveSuperAdminException extends LogicException
{
    public static function forUser(Authenticatable $user): self
    {
        return new self('User ['.self::idOf($user).'] would hold the super-admin role together with other roles; super-admin stands alone (decision 2026-11-26).');
    }

    public static function promotionBy(Authenticatable $user): self
    {
        return new self('User ['.self::idOf($user).'] would become an effective super-admin, and the acting user may not manage super-admins.');
    }

    private static function idOf(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? (string) $identifier : 'unknown';
    }
}

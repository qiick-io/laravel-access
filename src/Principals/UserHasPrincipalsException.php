<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use LogicException;

/**
 * A user who still has principals cannot be deleted: principals.user_id is
 * ON DELETE RESTRICT (decision 2026-11-24, erasure is a job). Products route
 * user deletion (for example Fortify's DeleteUser) through
 * PrincipalDirectory::eraseUser().
 *
 * @api
 */
final class UserHasPrincipalsException extends LogicException
{
    public static function forUser(int|string $userId): self
    {
        return new self("User [{$userId}] still has principals; delete it through PrincipalDirectory::eraseUser(), which erases and unlinks them first (design §15.5).");
    }
}

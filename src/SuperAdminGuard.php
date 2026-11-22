<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Throwable;

/**
 * Who may manage super-admins, which targets are protected, and whether a change
 * would leave zero active super-admins (completeness review §3.5 / A7).
 *
 * Counts through {@see EffectivePermissionResolver::isSuperAdmin()} (direct and
 * group), scoped to {@see ActiveUsers}. Break-glass local sign-in stays in
 * products / laravel-oidc (R7); they combine {@see EffectivePermissionResolver::isDirectSuperAdmin()}
 * with {@see TwoFactorPolicy::hasUsableFactor()}.
 *
 * @api
 */
final class SuperAdminGuard
{
    public function __construct(
        private readonly EffectivePermissionResolver $resolver,
        private readonly ActiveUsers $activeUsers,
        private readonly DatabaseManager $database,
    ) {}

    public function canManageSuperAdmins(?Authenticatable $actor): bool
    {
        return $actor !== null && $this->resolver->isSuperAdmin($actor);
    }

    public function isProtectedFrom(?Authenticatable $actor, Authenticatable $target): bool
    {
        return ! $this->canManageSuperAdmins($actor) && $this->resolver->isSuperAdmin($target);
    }

    /**
     * Runs `$change` in a transaction. When the instance already had an active
     * super-admin and would have none afterwards, rolls back and returns true.
     * Otherwise commits and returns false. Re-throws other failures after rollback.
     *
     * @param  callable(): mixed  $change
     */
    public function wouldLeaveNoActiveSuperAdmin(callable $change): bool
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            $hadActiveSuperAdmin = $this->hasActiveSuperAdmin();
            $change();
            $wouldLeave = $hadActiveSuperAdmin && ! $this->hasActiveSuperAdmin();

            if ($wouldLeave) {
                $connection->rollBack();

                return true;
            }

            $connection->commit();

            return false;
        } catch (Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    private function hasActiveSuperAdmin(): bool
    {
        foreach ($this->activeUsers->query()->cursor() as $user) {
            if ($user instanceof Authenticatable && $this->resolver->isSuperAdmin($user)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
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
 * `wouldLeaveNoActiveSuperAdmin` takes `SELECT … FOR UPDATE` locks on the
 * active-user set so concurrent demotions cannot both commit to zero (B1), and
 * recounts from the database with resolver/relation caches cleared (B2).
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
        return $this->resolver->isActorSuperAdmin($actor);
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
            $hadActiveSuperAdmin = $this->hasActiveSuperAdminUnderLock();
            $change();
            $wouldLeave = $hadActiveSuperAdmin && ! $this->hasActiveSuperAdminUnderLock();

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

    /**
     * Lock active users, then decide super-admin from a fresh DB read (no warm
     * resolver cache, no stale Eloquent role relations).
     */
    private function hasActiveSuperAdminUnderLock(): bool
    {
        /** @var Collection<int, mixed> $activeUsers */
        $activeUsers = $this->activeUsers->query()->lockForUpdate()->get();

        foreach ($activeUsers as $user) {
            if (! $user instanceof Authenticatable) {
                continue;
            }

            // Drop process-local cache and loaded roles/permissions so the next
            // isSuperAdmin() hits the database as it stands in this transaction.
            $this->resolver->forget($user);

            if ($this->resolver->isSuperAdmin($user)) {
                return true;
            }
        }

        return false;
    }
}

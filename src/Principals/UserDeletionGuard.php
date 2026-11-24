<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Listens to Eloquent `deleting` and refuses to delete a user who still has
 * principals with a clear UserHasPrincipalsException, before the database
 * refuses it with a raw foreign key error. A soft delete is allowed: it
 * keeps the row the principals point at.
 *
 * It listens to `eloquent.deleting: *`, so it runs for every model deletion;
 * for anything but the users model it costs a config read and an instanceof.
 */
final class UserDeletionGuard
{
    /**
     * @param  array<int, mixed>  $payload  the event payload: the model being deleted
     *
     * @throws UserHasPrincipalsException
     */
    public function __invoke(string $eventName, array $payload): void
    {
        $user = $payload[0] ?? null;
        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! is_a($userModel, Model::class, true) || ! $user instanceof $userModel) {
            return;
        }

        if (method_exists($user, 'isForceDeleting') && ! $user->isForceDeleting()) {
            return;
        }

        if (Schema::hasTable('principals') && Principal::query()->where('user_id', $user->getKey())->exists()) {
            $userId = $user->getKey();

            throw UserHasPrincipalsException::forUser(is_int($userId) || is_string($userId) ? $userId : '?');
        }
    }
}

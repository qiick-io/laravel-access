<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Illuminate\Database\Eloquent\Model;
use LogicException;

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
     * @throws LogicException when access.principals.model is invalid
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

        $principalModel = $this->principalModel();
        // Every principal counts, whatever global scopes a subclass adds.
        $principals = $principalModel->newQuery()->withoutGlobalScopes();

        if ($principalModel->getConnection()->getSchemaBuilder()->hasTable($principalModel->getTable()) && $principals->where('user_id', $user->getKey())->exists()) {
            $userId = $user->getKey();

            throw UserHasPrincipalsException::forUser(is_int($userId) || is_string($userId) ? $userId : '?');
        }
    }

    /**
     * The configured principal model, so a subclass on its own connection is
     * checked where its rows live.
     *
     * @throws LogicException when access.principals.model is not Principal or a
     *                        subclass: the guard fails closed, as PrincipalDirectory does
     */
    private function principalModel(): Principal
    {
        $modelClass = config('access.principals.model', Principal::class);

        if (! is_string($modelClass) || ! is_a($modelClass, Principal::class, true)) {
            throw new LogicException('access.principals.model must be Qiick\Access\Principals\Principal or a subclass.');
        }

        return new $modelClass;
    }
}

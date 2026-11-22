<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Qiick\Access\Contracts\TeamContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * TeamContext backed by Spatie's permissions team id (design §4.3 / C29).
 * switchTo sets the team id, flushes Spatie's permission cache, unsets the
 * current user's roles/permissions relations, and optionally forgets the
 * package resolver entry (wired when EffectivePermissionResolver lands).
 *
 * @api
 */
final class SpatieTeamContext implements TeamContext
{
    /**
     * @param  Closure(Authenticatable): void|null  $forgetUserPermissions
     */
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly AuthFactory $auth,
        private readonly ?Closure $forgetUserPermissions = null,
    ) {}

    public function currentId(): string|int|null
    {
        return $this->registrar->getPermissionsTeamId();
    }

    public function switchTo(string|int|null $id): void
    {
        $this->registrar->setPermissionsTeamId($id);
        $this->registrar->forgetCachedPermissions();

        $user = $this->auth->guard()->user();
        if ($user === null) {
            return;
        }

        if (method_exists($user, 'unsetRelation')) {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }

        if ($this->forgetUserPermissions !== null) {
            ($this->forgetUserPermissions)($user);
        }
    }
}

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
 * actor user's roles/permissions relations (AccessContext owner when bound,
 * otherwise the default guard user), and optionally forgets the package
 * resolver entry (wired when EffectivePermissionResolver lands).
 *
 * Products that enable teams must set permission.team_foreign_key to
 * organisation_id (standards MUST A3 / fleet core schema).
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

        $user = $this->resolveActorUser();
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

    /**
     * Prefer the verified AccessContext owner over the default guard, so an
     * API actor (or Gate::forUser path that entered the actor) is cleared.
     */
    private function resolveActorUser(): ?Authenticatable
    {
        $bound = AccessContext::current();
        if ($bound?->user !== null) {
            return $bound->user;
        }

        return $this->auth->guard()->user();
    }
}

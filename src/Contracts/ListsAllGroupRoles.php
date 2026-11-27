<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional for a GroupRoleSource: the roles of every live group of the user,
 * in every organisation. The exclusive super-admin check (decision
 * 2026-11-26) reads it; a source without it is checked on the current team
 * context and the instance groups only.
 *
 * @api
 */
interface ListsAllGroupRoles
{
    /**
     * @return list<int|string>
     */
    public function allRoleIdsFor(Authenticatable $user): array;
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Group roles. Soft-deleted groups contribute nothing (filtered in SQL).
 *
 * @api
 */
interface GroupRoleSource
{
    /**
     * Role ids from groups that apply to the user in the current team context:
     * instance groups, legacy unscoped groups, and organisation groups matching
     * {@see TeamContext::currentId()}.
     *
     * @return list<int|string>
     */
    public function roleIdsFor(Authenticatable $user): array;

    /**
     * Role ids from instance groups only (`is_instance = true`). Used for the
     * team-agnostic super-admin path (C30): organisation groups never elevate
     * to instance super-admin through membership alone.
     *
     * @return list<int|string>
     */
    public function instanceRoleIdsFor(Authenticatable $user): array;
}

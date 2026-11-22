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
     * @return list<int|string>
     */
    public function roleIdsFor(Authenticatable $user): array;
}

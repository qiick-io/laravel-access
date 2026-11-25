<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Product query of users that count as "active" for super-admin governance.
 *
 * Products differ (`is_active`, soft deletes); the package never hard-codes those.
 *
 * @api
 */
interface ActiveUsers
{
    /**
     * @return Builder<Model>
     */
    public function query(): Builder;
}

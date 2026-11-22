<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * Product role names used by the resolver (super-admin today).
 *
 * @api
 */
interface RoleCatalog
{
    /** 'super-admin' in all four products today. */
    public function superAdminRoleName(): string;
}

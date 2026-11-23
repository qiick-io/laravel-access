<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Spatie\Permission\Models\Role;

/**
 * Spatie role whose primary key is not `id`, for resolver tests that must
 * follow the role model's own key.
 */
final class KeyedRole extends Role
{
    protected $primaryKey = 'role_key';
}

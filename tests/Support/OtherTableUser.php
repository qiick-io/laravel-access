<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

/**
 * A member model on the default connection but another table than the one
 * user_group_user.user_id points at (codex review of #59).
 */
final class OtherTableUser extends SpatieUser
{
    protected $table = 'admins';
}

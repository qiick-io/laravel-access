<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

/**
 * A single-table-inheritance subclass: same table, same Spatie morph class
 * as its parent, the class a product sets as access.group_member_model.
 */
final class StiAdminUser extends SpatieUser
{
    public function getMorphClass(): string
    {
        return SpatieUser::class;
    }
}

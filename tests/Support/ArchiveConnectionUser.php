<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use LogicException;

/**
 * A user on the `archive` connection, not the default one where the group
 * tables live (issue #27). Its boot hook stands for one that needs services
 * a product sets up in its own providers: it refuses to boot earlier.
 */
final class ArchiveConnectionUser extends SpatieUser
{
    protected $connection = 'archive';

    protected static function booted(): void
    {
        if (! app()->isBooted()) {
            throw new LogicException('ArchiveConnectionUser was built before the application booted.');
        }
    }
}

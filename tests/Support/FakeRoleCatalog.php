<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Qiick\Access\Contracts\RoleCatalog;

/**
 * RoleCatalog stub for package Testbench boots (design §3.3 required binding).
 */
final class FakeRoleCatalog implements RoleCatalog
{
    public function superAdminRoleName(): string
    {
        return 'super-admin';
    }
}

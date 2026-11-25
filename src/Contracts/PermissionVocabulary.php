<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * The product's PermissionEnum surface.
 *
 * @api
 */
interface PermissionVocabulary
{
    public function isPermission(string $ability): bool;

    /**
     * Every permission name; what a super-admin holds.
     *
     * @return list<string>
     */
    public function all(): array;
}

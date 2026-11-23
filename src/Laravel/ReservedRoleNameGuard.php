<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Database\Eloquent\Model;
use Qiick\Access\Contracts\RoleCatalog;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Refuses to save a team-local role (team id set) with the reserved
 * super-admin name. The resolver already ignores such a role for the
 * instance super-admin (C30), so this is defence in depth: an organisation
 * admin who may create roles in their organisation gets a loud error instead
 * of a role that looks like super-admin. AccessServiceProvider runs it on the
 * Spatie role model's `saving` event.
 */
final class ReservedRoleNameGuard
{
    public function __construct(
        private readonly RoleCatalog $roleCatalog,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @throws RuntimeException when a team-local role takes the super-admin name
     */
    public function assertAllowed(Model $role): void
    {
        if (! $this->registrar->teams) {
            return;
        }

        if ($role->getAttribute('name') !== $this->roleCatalog->superAdminRoleName()) {
            return;
        }

        if ($role->getAttribute($this->registrar->teamsKey) === null) {
            return;
        }

        throw new RuntimeException(
            "The role name [{$this->roleCatalog->superAdminRoleName()}] is reserved for the global instance role; a team-local role cannot use it (C30).",
        );
    }
}

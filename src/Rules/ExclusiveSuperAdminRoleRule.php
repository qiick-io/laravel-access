<?php

declare(strict_types=1);

namespace Qiick\Access\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Qiick\Access\Contracts\RoleCatalog;

/**
 * Validates a list of role names for one user: the super-admin role stands
 * alone (decision 2026-11-26). Super-admin already holds every permission, so
 * another role next to it grants nothing and only hides who is a super-admin;
 * every other role combines freely, and their permissions add up.
 *
 * Products put it on the role field of their user requests, next to their own
 * rules (`['array', new ExclusiveSuperAdminRoleRule]`); a value that is not a
 * list is left to those rules. Roles are given by name: an entry that is not
 * a string fails, and names compare case-insensitively. The role picker disables the other roles once
 * super-admin is picked, so this is the trust-boundary check behind it.
 *
 * @api
 */
final class ExclusiveSuperAdminRoleRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        if (array_filter($value, is_string(...)) !== $value) {
            // A role id could name the super-admin row without its name.
            $fail('Roles must be given by name.')->translate();

            return;
        }

        // Case-folded: a case-insensitive role store resolves `Super-Admin`
        // to the super-admin row (review of PR #58).
        $roleNames = array_unique(array_map(mb_strtolower(...), $value));
        $superAdminRoleName = mb_strtolower(app(RoleCatalog::class)->superAdminRoleName());

        if (in_array($superAdminRoleName, $roleNames, true) && count($roleNames) > 1) {
            $fail('The super-admin role cannot be combined with other roles.')->translate();
        }
    }
}

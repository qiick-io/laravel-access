<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A product's own pre-package Gate::before decision, kept for the
 * compatibility period of design §9.2 step 6 / §9.5 (externa only).
 *
 * Bind it when access.use_package_gate is false (it then decides instead of
 * GateBefore) or when access.gate_shadow is true (it then runs next to the
 * deciding callback so disagreements are logged). Removed in v2 with both
 * switches.
 *
 * @api
 *
 * @deprecated 2.0 the legacy callback and its switches are removed in v2
 */
interface LegacyGateBefore
{
    /**
     * Same contract as a Gate::before callback: true grants, false denies,
     * null abstains so the Gate continues to abilities and Policies.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function decide(?Authenticatable $user, string $ability, array $arguments = []): ?bool;
}

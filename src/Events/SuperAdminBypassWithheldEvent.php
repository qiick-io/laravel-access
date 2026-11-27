<?php

declare(strict_types=1);

namespace Qiick\Access\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A super-admin also holds other roles, so the resolver withheld the
 * super-admin bypass (fail-closed, decision 2026-11-26). Products notify the
 * other super-admins or their security log; `access:check` lists such users.
 * Dispatched once per scope when the verdict is first computed.
 *
 * @api
 */
final class SuperAdminBypassWithheldEvent
{
    public function __construct(
        public readonly Authenticatable $user,
    ) {}
}

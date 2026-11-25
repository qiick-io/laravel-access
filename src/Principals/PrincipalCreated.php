<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after PrincipalDirectory creates a principal (design §15.4),
 * once the surrounding transaction commits, as PrincipalDeactivated is: a
 * listener (an audit entry, a welcome key) never sees a principal that a
 * rollback then removed (AC-c). Without a transaction it is dispatched at
 * once.
 *
 * @api
 */
final class PrincipalCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Principal $principal,
    ) {}
}

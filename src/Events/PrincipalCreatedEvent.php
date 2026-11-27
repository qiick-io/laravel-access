<?php

declare(strict_types=1);

namespace Qiick\Access\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Qiick\Access\Principals\Principal;

/**
 * Dispatched after PrincipalDirectory creates a principal (design §15.4),
 * once the surrounding transaction commits, as PrincipalDeactivatedEvent is: a
 * listener (an audit entry, a welcome key) never sees a principal that a
 * rollback then removed (AC-c). Without a transaction it is dispatched at
 * once.
 *
 * @api
 */
final class PrincipalCreatedEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Principal $principal,
    ) {}
}

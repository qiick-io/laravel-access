<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

/**
 * Dispatched after PrincipalDirectory creates a principal (design §15.4).
 *
 * @api
 */
final class PrincipalCreated
{
    public function __construct(
        public readonly Principal $principal,
    ) {}
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * Reads only what the server bound after verifying a credential.
 * Never request input (design §3.3 / AC4).
 *
 * @api
 */
interface ActorResolver
{
    public function current(): ActorContext;
}

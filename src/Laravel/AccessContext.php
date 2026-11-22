<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Qiick\Access\Contracts\ActorContext;

/**
 * Request-scoped (and job-scoped) store for the verified actor.
 * Products call enter() only after a credential passes (design §3.3).
 *
 * @api
 */
final class AccessContext
{
    private static ?ActorContext $current = null;

    public static function enter(ActorContext $context): void
    {
        self::$current = $context;
    }

    public static function current(): ?ActorContext
    {
        return self::$current;
    }

    public static function clear(): void
    {
        self::$current = null;
    }
}

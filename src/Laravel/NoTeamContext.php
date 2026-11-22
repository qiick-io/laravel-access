<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Qiick\Access\Contracts\TeamContext;

/**
 * Default TeamContext when Spatie teams are off (all four products today).
 * switchTo is a no-op: there is no organisation id to track (design §4.3).
 *
 * @api
 */
final class NoTeamContext implements TeamContext
{
    public function currentId(): string|int|null
    {
        return null;
    }

    public function switchTo(string|int|null $id): void
    {
        // Teams disabled: ignore the call so product middleware can stay uniform.
    }
}

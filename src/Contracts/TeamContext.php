<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * Spatie team id for the current request. Product middleware calls
 * switchTo() before SubstituteBindings.
 *
 * @api
 */
interface TeamContext
{
    public function currentId(): string|int|null;

    public function switchTo(string|int|null $id): void;
}

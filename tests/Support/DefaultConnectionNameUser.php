<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

/**
 * A user that names the default connection explicitly on every new instance,
 * instead of leaving it null (issue #27).
 */
final class DefaultConnectionNameUser extends SpatieUser
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection((string) config('database.default'));
    }
}

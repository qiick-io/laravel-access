<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Minimal Eloquent user with Spatie HasRoles for resolver feature tests.
 */
class SpatieUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

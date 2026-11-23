<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Http\Request;
use Qiick\Access\Http\Concerns\AuthorizesWithPermission;

/**
 * Stands in for a product FormRequest using AuthorizesWithPermission. Also
 * listed in phpstan.neon so the trait is analysed.
 */
final class PermissionGatedRequest extends Request
{
    use AuthorizesWithPermission;

    public function authorize(): bool
    {
        $this->authorizePermission(FakePermissionEnum::CanEditUsers->value);

        return true;
    }
}

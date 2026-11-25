<?php

declare(strict_types=1);

namespace Qiick\Access\Http\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Qiick\Access\Http\EnsureUserHasPermission;

/**
 * FormRequest `authorize()` helper (design §6): the same decision and error
 * body as the `permission:` middleware, API-actor rules included.
 *
 *     public function authorize(): bool
 *     {
 *         $this->authorizePermission(PermissionEnum::CanEditUsers->value);
 *
 *         return true;
 *     }
 *
 * @phpstan-require-extends Request
 *
 * @api
 */
trait AuthorizesWithPermission
{
    /**
     * @throws HttpResponseException with the product's forbidden response when
     *                               the actor lacks the permission
     */
    protected function authorizePermission(string $permission): void
    {
        $forbiddenResponse = app(EnsureUserHasPermission::class)->forbiddenResponse($this, $permission);

        if ($forbiddenResponse !== null) {
            throw new HttpResponseException($forbiddenResponse);
        }
    }
}

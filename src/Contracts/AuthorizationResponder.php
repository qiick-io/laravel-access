<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product HTTP body for permission middleware denials.
 *
 * @api
 */
interface AuthorizationResponder
{
    public function forbidden(Request $request, string $permission): Response;
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Http;

use Closure;
use Illuminate\Http\Request;
use Qiick\Access\Laravel\AccessContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears any leftover actor at the start of each HTTP request so a recycled
 * worker (Octane) or a previous test cannot leak into this request.
 */
final class ClearAccessContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        AccessContext::clear();

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}

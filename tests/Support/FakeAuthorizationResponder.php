<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Http\Request;
use Qiick\Access\Contracts\AuthorizationResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthorizationResponder stub so AccessServiceProvider boot checks pass in Testbench.
 */
final class FakeAuthorizationResponder implements AuthorizationResponder
{
    public function forbidden(Request $request, string $permission): Response
    {
        return new Response('forbidden', 403);
    }
}

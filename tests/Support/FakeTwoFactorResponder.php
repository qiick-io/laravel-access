<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Http\Request;
use Qiick\Access\Contracts\TwoFactorResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * TwoFactorResponder stub so AccessServiceProvider boot checks pass in Testbench.
 */
final class FakeTwoFactorResponder implements TwoFactorResponder
{
    public function enrol(Request $request): Response
    {
        return new Response('enrol', 302);
    }

    public function unavailable(): Response
    {
        return new Response('unavailable', 503);
    }
}

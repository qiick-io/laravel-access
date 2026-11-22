<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product HTTP responses for 2FA enrolment and unavailable methods.
 *
 * @api
 */
interface TwoFactorResponder
{
    /** Redirect (or JSON), keeping the intended URL for a non-JSON GET. */
    public function enrol(Request $request): Response;

    public function unavailable(): Response;
}

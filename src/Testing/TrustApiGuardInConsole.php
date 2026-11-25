<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

/**
 * Product PHPUnit helper: allow the API-guard signal while runningInConsole()
 * is true. Call enable() in a test (or TestCase setUp) when exercising the
 * guard path; call disable() in tearDown. ContainerActorResolver also requires
 * environment('testing'), so this flag is a no-op outside the test suite.
 *
 * @api
 */
final class TrustApiGuardInConsole
{
    public static function enable(): void
    {
        config(['access.trust_api_guard_in_console' => true]);
    }

    public static function disable(): void
    {
        config(['access.trust_api_guard_in_console' => false]);
    }
}

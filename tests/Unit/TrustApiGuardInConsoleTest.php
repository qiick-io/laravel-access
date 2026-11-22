<?php

declare(strict_types=1);

use Qiick\Access\Testing\TrustApiGuardInConsole;

it('toggles access.trust_api_guard_in_console for product PHPUnit suites', function (): void {
    config(['access.trust_api_guard_in_console' => false]);

    TrustApiGuardInConsole::enable();
    expect(config('access.trust_api_guard_in_console'))->toBeTrue();

    TrustApiGuardInConsole::disable();
    expect(config('access.trust_api_guard_in_console'))->toBeFalse();
});

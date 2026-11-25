<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function accessConfigWithEnvironment(string $name, ?string $value): array
{
    $value === null ? putenv($name) : putenv("{$name}={$value}");

    try {
        return require dirname(__DIR__, 2).'/config/access.php';
    } finally {
        putenv($name);
    }
}

it('reads web guards from ACCESS_WEB_GUARDS, defaulting to web', function (): void {
    expect(accessConfigWithEnvironment('ACCESS_WEB_GUARDS', null)['web_guards'])->toBe(['web'])
        ->and(accessConfigWithEnvironment('ACCESS_WEB_GUARDS', ' web , filament ,,')['web_guards'])->toBe(['web', 'filament']);
});

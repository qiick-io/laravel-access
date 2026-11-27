<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Qiick\Access\Rules\ExclusiveSuperAdminRoleRule;

/*
 * Decision 2026-11-26: super-admin is exclusive, every other role combines.
 */

function roleErrors(mixed $roles): array
{
    return Validator::make(['roles' => $roles], ['roles' => [new ExclusiveSuperAdminRoleRule]])->errors()->get('roles');
}

it('rejects super-admin next to another role', function (): void {
    expect(roleErrors(['super-admin', 'admin']))->toBe(['The super-admin role cannot be combined with other roles.'])
        ->and(roleErrors(['viewer', 'super-admin']))->toHaveCount(1);
});

it('accepts super-admin alone, also when repeated', function (): void {
    expect(roleErrors(['super-admin']))->toBe([])
        ->and(roleErrors(['super-admin', 'super-admin']))->toBe([]);
});

it('accepts any combination of other roles', function (): void {
    expect(roleErrors(['admin', 'viewer']))->toBe([])
        ->and(roleErrors([]))->toBe([]);
});

it('leaves a value that is not a list to the other rules', function (): void {
    expect(roleErrors('super-admin'))->toBe([])
        ->and(roleErrors(null))->toBe([]);
});

it('compares the role name case-insensitively', function (): void {
    expect(roleErrors(['Super-Admin', 'admin']))->toHaveCount(1)
        ->and(roleErrors(['super-admin', 'SUPER-ADMIN']))->toBe([]);
});

it('rejects roles that are not given by name', function (): void {
    expect(roleErrors([1, 'admin']))->toBe(['Roles must be given by name.']);
});

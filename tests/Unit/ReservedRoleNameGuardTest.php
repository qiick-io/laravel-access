<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Qiick\Access\Laravel\ReservedRoleNameGuard;
use Qiick\Access\Tests\Support\FakeRoleCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  array<string, mixed>  $attributes
 */
function unsavedRole(array $attributes): Model
{
    return (new class extends Model {})->forceFill($attributes);
}

function reservedRoleNameGuard(bool $isTeamsEnabled): ReservedRoleNameGuard
{
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = $isTeamsEnabled;
    $registrar->teamsKey = 'organisation_id';

    return new ReservedRoleNameGuard(new FakeRoleCatalog, $registrar);
}

it('refuses a team-local role with the reserved name under teams', function (): void {
    expect(fn () => reservedRoleNameGuard(true)->assertAllowed(unsavedRole(['name' => 'super-admin', 'organisation_id' => 'org-1'])))
        ->toThrow(RuntimeException::class, 'The role name [super-admin] is reserved');
});

it('allows the global reserved role, other names, and anything without teams', function (): void {
    reservedRoleNameGuard(true)->assertAllowed(unsavedRole(['name' => 'super-admin', 'organisation_id' => null]));
    reservedRoleNameGuard(true)->assertAllowed(unsavedRole(['name' => 'org-editor', 'organisation_id' => 'org-1']));
    // Spatie ignores team columns when teams are off, so the row is global.
    reservedRoleNameGuard(false)->assertAllowed(unsavedRole(['name' => 'super-admin', 'organisation_id' => 'org-1']));

    expect(true)->toBeTrue();
});

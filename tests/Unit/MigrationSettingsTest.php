<?php

declare(strict_types=1);

use Qiick\Access\Laravel\MigrationSettings;
use Qiick\Access\Tests\Support\KeyedRole;
use Qiick\Access\Tests\Support\SpatieUser;

it('resolves the users table from access.users_table, then the auth users model, then users', function (): void {
    config()->set('access.users_table', 'accounts');
    expect(MigrationSettings::usersTable())->toBe('accounts');

    config()->set('access.users_table', '');
    config()->set('auth.providers.users.model', KeyedRole::class);
    expect(MigrationSettings::usersTable())->toBe('roles');

    config()->set('auth.providers.users.model', SpatieUser::class);
    expect(MigrationSettings::usersTable())->toBe('users');

    config()->set('auth.providers.users.model', stdClass::class);
    expect(MigrationSettings::usersTable())->toBe('users');

    config()->set('access.users_table', null);
});

it('requires organisations only for a boolean true', function (mixed $configured, bool $isRequired): void {
    config()->set('access.principals.organisation_required', $configured);

    expect(MigrationSettings::isOrganisationRequired())->toBe($isRequired);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'string one' => ['1', false],
    'missing' => [null, false],
]);

it('returns validated instance types', function (): void {
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service', 'a1_b']);
    expect(MigrationSettings::instanceTypes())->toBe(['instance_service', 'a1_b']);

    config()->set('access.principals.instance_types', null);
    expect(fn () => MigrationSettings::instanceTypes())->toThrow(InvalidArgumentException::class);

    config()->set('access.principals.instance_types', []);
    expect(MigrationSettings::instanceTypes())->toBe([]);

    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', ['instance_service']);
    expect(fn () => MigrationSettings::instanceTypes())->toThrow(InvalidArgumentException::class, 'organisation_required');

    config()->set('access.principals.instance_types', []);
    expect(MigrationSettings::instanceTypes())->toBe([]);
});

it('returns the Eloquent users model and refuses anything else', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    expect(MigrationSettings::userModel())->toBe(SpatieUser::class);

    foreach ([stdClass::class, null] as $configured) {
        config()->set('auth.providers.users.model', $configured);
        expect(fn () => MigrationSettings::userModel())->toThrow(LogicException::class, 'Eloquent model');
    }
});

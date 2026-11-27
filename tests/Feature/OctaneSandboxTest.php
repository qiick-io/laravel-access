<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * #69: under Octane each request runs in a clone of the boot container (the
 * sandbox). GateBefore and the provider's listeners must use the container
 * of the current request, not the boot container they were registered with.
 * Simulated as Octane does it: clone, set as the container and facade root,
 * forget scoped instances.
 */

beforeEach(function (): void {
    config()->set('permission.teams', false);
    config()->set('auth.providers.users.model', SpatieUser::class);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $noGroups = Mockery::mock(GroupRoleSource::class);
    $noGroups->shouldReceive('roleIdsFor', 'instanceRoleIdsFor')->andReturn([]);
    app()->instance(GroupRoleSource::class, $noGroups);
    app()->forgetInstance(EffectivePermissionResolver::class);

    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
});

afterEach(function (): void {
    Container::setInstance($this->app);
    Facade::setFacadeApplication($this->app);

    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function enterOctaneSandbox(): Container
{
    $sandbox = clone app();
    Container::setInstance($sandbox);
    Facade::setFacadeApplication($sandbox);
    Facade::clearResolvedInstances();
    $sandbox->forgetScopedInstances();

    return $sandbox;
}

it('clears the resolver of the request when a role is detached in the sandbox', function (): void {
    $user = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $user->assignRole('super-admin');

    $sandbox = enterOctaneSandbox();
    $resolver = $sandbox->make(EffectivePermissionResolver::class);
    expect($resolver->isSuperAdmin($user))->toBeTrue();

    $user->removeRole('super-admin');

    expect($resolver->isSuperAdmin($user))->toBeFalse();
});

it('lets GateBefore read the resolver of the request, not the boot container', function (): void {
    $user = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $user->assignRole('super-admin');

    enterOctaneSandbox();
    expect(Gate::forUser($user)->allows('anything'))->toBeTrue();

    // A product write the package sees no event for, followed by the
    // documented forget() on the request's resolver.
    $user->roles()->detach();
    app(EffectivePermissionResolver::class)->forget($user);

    expect(Gate::forUser($user)->allows('anything'))->toBeFalse();
});

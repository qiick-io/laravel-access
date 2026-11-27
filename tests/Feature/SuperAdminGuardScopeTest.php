<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\SuperAdminGuard;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Issue #66: the guard must use the current scope's resolver. A singleton
 * guard kept the first scope's resolver, whose cache no request or job
 * boundary flushes, so a super-admin verdict outlived a demotion in a
 * long-lived worker.
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

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    app()->instance(GroupRoleSource::class, $groupRoles);

    app()->instance(ActiveUsers::class, new class implements ActiveUsers
    {
        public function query(): Builder
        {
            return SpatieUser::query();
        }
    });
});

afterEach(function (): void {
    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('forgets a super-admin verdict of the previous scope after a demotion (#66)', function (): void {
    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $user = SpatieUser::query()->create(['email' => 'admin@example.com']);
    $user->assignRole('super-admin');

    expect(app(SuperAdminGuard::class)->isProtectedFrom(null, $user))->toBeTrue();

    // A demotion the resolver hears nothing about, then the next job or request.
    DB::table('model_has_roles')->delete();
    app()->forgetScopedInstances();

    expect(app(SuperAdminGuard::class)->isProtectedFrom(null, $user->fresh()))->toBeFalse();
});

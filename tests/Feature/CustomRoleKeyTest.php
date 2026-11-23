<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\KeyedRole;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\PermissionRegistrar;

/*
 * The resolver reads role ids through the role model's own key, never a
 * hard-coded `id`, so a product role model with another primary key works.
 */

beforeEach(function (): void {
    config()->set('permission.teams', false);
    config()->set('permission.models.role', KeyedRole::class);
    config()->set('permission.column_names.role_pivot_key', 'role_key');
    config()->set('auth.providers.users.model', SpatieUser::class);
    $this->app->forgetInstance(PermissionRegistrar::class);

    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });
    Schema::create('roles', function (Blueprint $table): void {
        $table->bigIncrements('role_key');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('permissions', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('role_has_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_key');
    });
    Schema::create('model_has_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
    Schema::create('model_has_roles', function (Blueprint $table): void {
        $table->unsignedBigInteger('role_key');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
});

afterEach(function (): void {
    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('resolves direct and group roles through a role model whose key is not id', function (): void {
    $now = now();
    $permissionId = DB::table('permissions')->insertGetId(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
    $directRoleKey = DB::table('roles')->insertGetId(['role_key' => 40, 'name' => 'direct-role', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now], 'role_key');
    $groupRoleKey = DB::table('roles')->insertGetId(['role_key' => 70, 'name' => 'group-viewer', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now], 'role_key');
    DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_key' => $groupRoleKey]);

    $user = SpatieUser::query()->create(['email' => 'keyed@example.com']);
    DB::table('model_has_roles')->insert(['role_key' => $directRoleKey, 'model_type' => SpatieUser::class, 'model_id' => $user->id]);

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([$groupRoleKey]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->effectiveRoleIds($user))->toBe(['40', '70'])
        ->and($resolver->roleNamesFor($user))->toBe(['direct-role', 'group-viewer'])
        ->and($resolver->permissionsFor($user))->toBe([FakePermissionEnum::CanShowUsers->value]);
});

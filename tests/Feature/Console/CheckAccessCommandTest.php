<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Tests\Support\SpatieUser;
use Qiick\Access\Tests\Support\StiAdminUser;
use Spatie\Permission\Models\Role;

/*
 * F2: access:check exits 0 on a ready install and 1 with one line per
 * problem, for the deploy pipeline.
 */

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    $migration->up();
    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    foreach (['user_group_role', 'user_group_user', 'user_groups', 'principals', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users', 'migrations'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('passes on a configured and migrated install (F2)', function (): void {
    $this->artisan('access:check')
        ->expectsOutputToContain('configuration, bindings and tables are ready')
        ->assertSuccessful();
});

it('prints one line per missing table and exits 1 (F2)', function (string $missingTable): void {
    Schema::drop($missingTable);
    Schema::drop('user_group_role');

    $exitCode = Artisan::call('access:check');
    $problemLines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    expect($exitCode)->toBe(1)
        ->and($problemLines)->toBe([
            "Table [{$missingTable}] is missing on the default connection; run the migrations.",
            'Table [user_group_role] is missing on the default connection; run the migrations.',
        ]);
})->with(['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'user_groups', 'user_group_user']);

it('names the principals table after the others (F2)', function (): void {
    Schema::drop('principals');

    $this->artisan('access:check')
        ->expectsOutputToContain('Table [principals] is missing on the default connection')
        ->assertFailed();
});

it('skips the principals table when its migrations are off (F2)', function (): void {
    Schema::drop('principals');
    config()->set('access.migrations.principals', false);

    $this->artisan('access:check')->assertSuccessful();

    // An older published config without the switch loads the migration.
    config()->set('access.migrations', ['groups' => true]);

    $this->artisan('access:check')
        ->expectsOutputToContain('Table [principals] is missing')
        ->assertFailed();
});

it('reads the Spatie table names from the permission config (F2)', function (): void {
    config()->set('permission.table_names.roles', 'access_roles');

    $this->artisan('access:check')
        ->expectsOutputToContain('Table [access_roles] is missing')
        ->assertFailed();

    // Spatie uses the configured name as is: missing or empty is a problem.
    config()->set('permission.table_names.roles', 'roles');
    config()->set('permission.table_names.model_has_roles', '');
    config()->set('permission.table_names.role_has_permissions', null);

    $this->artisan('access:check')
        ->expectsOutputToContain('permission.table_names.model_has_roles must be a non-empty table name.')
        ->expectsOutputToContain('permission.table_names.role_has_permissions must be a non-empty table name.')
        ->assertFailed();
});

it('reports a failed boot check (F2)', function (): void {
    config()->set('permission.events_enabled', false);

    $this->artisan('access:check')
        ->expectsOutputToContain('permission.events_enabled must be true')
        ->assertFailed();
});

it('reports a resolver that cannot be built, which boot never builds (F2)', function (): void {
    config()->set('access.permission_enum', null);
    app()->forgetInstance(PermissionVocabulary::class);
    app()->forgetScopedInstances();

    $this->artisan('access:check')
        ->expectsOutputToContain('Set access.permission_enum')
        ->assertFailed();
});

it('reports an unreachable primary behind a healthy replica (F2)', function (): void {
    $defaultConnection = DB::getDefaultConnection();
    $replicaDatabase = tempnam(sys_get_temp_dir(), 'access-replica');
    config()->set('database.connections.split', [
        'driver' => 'sqlite',
        'read' => ['database' => $replicaDatabase],
        'write' => ['database' => '/nonexistent/access.sqlite'],
    ]);
    DB::setDefaultConnection('split');

    try {
        $this->artisan('access:check')
            ->expectsOutputToContain('The default database connection [split] is unreachable')
            ->assertFailed();
    } finally {
        DB::setDefaultConnection($defaultConnection);
        DB::purge('split');
        unlink($replicaDatabase);
    }
});

it('reports an unreachable default database (F2)', function (): void {
    $defaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => '/nonexistent/access.sqlite']);
    DB::setDefaultConnection('unreachable');

    try {
        $this->artisan('access:check')
            ->expectsOutputToContain('The default database connection [unreachable] is unreachable')
            ->assertFailed();
    } finally {
        DB::setDefaultConnection($defaultConnection);
    }
});

it('reads the tables on the primary, not on a replica that has them (F2)', function (): void {
    // Codex review of #62: hasTable() selects through the read PDO.
    $defaultConnection = DB::getDefaultConnection();
    $primaryDatabase = tempnam(sys_get_temp_dir(), 'access-primary');
    $replicaDatabase = tempnam(sys_get_temp_dir(), 'access-replica');
    $replica = new PDO('sqlite:'.$replicaDatabase);

    foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'user_groups', 'user_group_user', 'user_group_role', 'principals'] as $table) {
        $replica->exec("CREATE TABLE {$table} (id INTEGER)");
    }

    config()->set('database.connections.split', [
        'driver' => 'sqlite',
        'read' => ['database' => $replicaDatabase],
        'write' => ['database' => $primaryDatabase],
    ]);
    DB::setDefaultConnection('split');

    try {
        $this->artisan('access:check')
            ->expectsOutputToContain('Table [roles] is missing on the default connection')
            ->assertFailed();

        // Reads go back to the replica afterwards.
        expect(DB::connection('split')->select("SELECT name FROM sqlite_master WHERE name = 'roles'"))->toHaveCount(1);
    } finally {
        DB::setDefaultConnection($defaultConnection);
        DB::purge('split');
        unlink($primaryDatabase);
        unlink($replicaDatabase);
    }
});

it('does not require the group tables for a product group source with the group migrations off (F2)', function (): void {
    config()->set('access.migrations.groups', false);
    app()->forgetScopedInstances();
    app()->instance(GroupRoleSource::class, new class implements GroupRoleSource
    {
        public function roleIdsFor(Authenticatable $user): array
        {
            return [];
        }

        public function instanceRoleIdsFor(Authenticatable $user): array
        {
            return [];
        }
    });

    foreach (['user_group_role', 'user_group_user', 'user_groups'] as $table) {
        Schema::drop($table);
    }

    // Its tables are its own; its super-admins cannot be listed (C31).
    $exitCode = Artisan::call('access:check');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->not->toContain('Table [user_group')
        ->toContain('cannot be listed');
});

it('still requires the group tables when the package source reads product-owned tables (F2)', function (): void {
    // Externa 1.x: group migrations off, the same tables owned by the product.
    config()->set('access.migrations.groups', false);
    Schema::drop('user_group_role');

    $this->artisan('access:check')
        ->expectsOutputToContain('Table [user_group_role] is missing on the default connection')
        ->assertFailed();
});

it('keeps the configuration problems next to an unreachable database (F2)', function (): void {
    $defaultConnection = DB::getDefaultConnection();
    config()->set('permission.table_names.roles', '');
    config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => '/nonexistent/access.sqlite']);
    DB::setDefaultConnection('unreachable');

    try {
        $exitCode = Artisan::call('access:check');

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('permission.table_names.roles must be a non-empty table name.')
            ->toContain('The default database connection [unreachable] is unreachable');
    } finally {
        DB::setDefaultConnection($defaultConnection);
        DB::purge('unreachable');
    }
});

it('leaves the group tables to a product source that cannot be built here (F2)', function (): void {
    config()->set('access.migrations.groups', false);
    app()->forgetScopedInstances();
    app()->bind(GroupRoleSource::class, static fn (): never => throw new LogicException('Needs a request.'));

    foreach (['user_group_role', 'user_group_user', 'user_groups'] as $table) {
        Schema::drop($table);
    }

    Artisan::call('access:check');

    expect(Artisan::output())->not->toContain('Table [user_groups]');
});

it('lists the users who hold super-admin together with other roles (C31)', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    $superAdmin = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $reader = Role::create(['name' => 'reader', 'guard_name' => 'web']);
    $alone = SpatieUser::query()->create();
    $alone->roles()->attach($superAdmin);
    $direct = SpatieUser::query()->create();
    $direct->roles()->attach([$superAdmin->id, $reader->id]);
    $throughGroup = SpatieUser::query()->create();
    $throughGroup->roles()->attach($reader);
    // Also a direct super-admin: listed once.
    $twice = SpatieUser::query()->create();
    $twice->roles()->attach([$superAdmin->id, $reader->id]);
    $groupId = DB::table('user_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'admins', 'is_instance' => true]);
    DB::table('user_group_role')->insert(['user_group_id' => $groupId, 'role_id' => $superAdmin->id]);
    DB::table('user_group_user')->insert([['user_group_id' => $groupId, 'user_id' => $throughGroup->id], ['user_group_id' => $groupId, 'user_id' => $twice->id]]);

    $exitCode = Artisan::call('access:check');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("User [{$direct->id}] holds super-admin together with other roles")
        ->toContain("User [{$throughGroup->id}] holds super-admin together with other roles")
        ->not->toContain("User [{$alone->id}]")
        ->and(substr_count($output, "User [{$twice->id}]"))->toBe(1);
});

it('passes with a super-admin who holds nothing else (C31)', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    SpatieUser::query()->create()->roles()->attach(Role::create(['name' => 'super-admin', 'guard_name' => 'web']));

    $this->artisan('access:check')->assertSuccessful();
});

it('lists a mixed holder through the groups of an STI member model (C31)', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    config()->set('access.group_member_model', StiAdminUser::class);
    $superAdmin = Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
    $member = SpatieUser::query()->create();
    $member->roles()->attach(Role::create(['name' => 'reader', 'guard_name' => 'web']));
    $groupId = DB::table('user_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'admins', 'is_instance' => true]);
    DB::table('user_group_role')->insert(['user_group_id' => $groupId, 'role_id' => $superAdmin->id]);
    DB::table('user_group_user')->insert(['user_group_id' => $groupId, 'user_id' => $member->id]);

    $this->artisan('access:check')
        ->expectsOutputToContain("User [{$member->id}] holds super-admin together with other roles")
        ->assertFailed();
});

it('lists direct holders through the users model when the group member model is not Eloquent (C31)', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    config()->set('access.group_member_model', stdClass::class);
    $mixed = SpatieUser::query()->create();
    $mixed->roles()->attach([Role::create(['name' => 'super-admin', 'guard_name' => 'web'])->id, Role::create(['name' => 'reader', 'guard_name' => 'web'])->id]);

    $this->artisan('access:check')
        ->expectsOutputToContain("User [{$mixed->id}] holds super-admin together with other roles")
        ->assertFailed();
});

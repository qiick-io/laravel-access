<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\PermissionVocabulary;

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
});

it('reads the Spatie table names from the permission config (F2)', function (): void {
    config()->set('permission.table_names.roles', 'access_roles');

    $this->artisan('access:check')
        ->expectsOutputToContain('Table [access_roles] is missing')
        ->assertFailed();

    // An empty name falls back to Spatie's default.
    config()->set('permission.table_names.roles', '');

    $this->artisan('access:check')->assertSuccessful();
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

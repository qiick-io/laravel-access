<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/** Uses the real package and grant schema, with no enclosing test transaction. */
abstract class ErasureTestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.providers.users.model', SpatieUser::class);
        config()->set('permission.teams', true);
        config()->set('permission.column_names.team_foreign_key', 'organisation_id');
        config()->set('access.principals.organisation_required', true);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
        });
        UuidTeamPermissionMigration::make()->up();
        (require dirname(__DIR__, 2).'/database/migrations/groups/2026_11_22_120000_create_user_groups_tables.php')->up();
        (require dirname(__DIR__, 2).'/database/migrations/principals/2026_11_24_100000_create_principals_table.php')->up();

        $registrar = app(PermissionRegistrar::class);
        $registrar->teams = true;
        $registrar->teamsKey = 'organisation_id';
        $registrar->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        try {
            foreach (['principals', 'user_group_user', 'user_group_role', 'user_groups', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'permissions', 'users'] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            parent::tearDown();
        }
    }
}

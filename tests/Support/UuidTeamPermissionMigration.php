<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Database\Migrations\Migration;

/** Builds Spatie's stock migration with the fleet's UUID team column type (A3). */
final class UuidTeamPermissionMigration
{
    public static function make(): Migration
    {
        $stub = (string) file_get_contents(dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub');
        $path = tempnam(sys_get_temp_dir(), 'access-team-migration-');

        if ($path === false) {
            throw new \RuntimeException('Could not allocate the test migration.');
        }

        try {
            file_put_contents($path, str_replace("\$table->unsignedBigInteger(\$columnNames['team_foreign_key'])", "\$table->uuid(\$columnNames['team_foreign_key'])", $stub));

            return require $path;
        } finally {
            unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Laravel\MigrationSettings;

/*
 * Package-owned group tables (standards A12 / decision 2026-11-22, Q5).
 * Names are fixed: user_groups, user_group_user, user_group_role — never
 * configurable. Spatie's tables stay Spatie's; this migration never creates
 * or alters them. role_id references permission.table_names.roles
 * (default "roles"). user_id references access.users_table or the auth
 * users provider model table (default "users"). Products generate
 * user_groups.public_id as UUID v7 in their model / factory.
 *
 * organisation_id (nullable uuid) scopes a group to one organisation;
 * is_instance marks the instance-level super-admin group (organisation_id
 * must be NULL). Externa 1.x leaves both unused (false / null).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');
        $rolesTable = is_string($rolesTable) && $rolesTable !== '' ? $rolesTable : 'roles';
        $usersTable = MigrationSettings::usersTable();

        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('organisation_id')->nullable()->index();
            $table->boolean('is_instance')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->addInstanceOrganisationXorConstraint();

        Schema::create('user_group_user', function (Blueprint $table) use ($usersTable): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($usersTable)->cascadeOnDelete();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('user_group_role', function (Blueprint $table) use ($rolesTable): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->unique(['user_group_id', 'role_id']);
            $table->index('role_id');

            $table->foreign('role_id')
                ->references('id')
                ->on($rolesTable)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_role');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }

    /**
     * is_instance=true only with organisation_id NULL (AC17 / C75).
     * SQLite has no ALTER ADD CONSTRAINT; enforce with INSERT/UPDATE triggers.
     */
    private function addInstanceOrganisationXorConstraint(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE TRIGGER user_groups_instance_org_xor_insert
                 BEFORE INSERT ON user_groups
                 FOR EACH ROW
                 WHEN NEW.is_instance = 1 AND NEW.organisation_id IS NOT NULL
                 BEGIN
                     SELECT RAISE(ABORT, \'is_instance requires null organisation_id\');
                 END',
            );
            DB::statement(
                'CREATE TRIGGER user_groups_instance_org_xor_update
                 BEFORE UPDATE ON user_groups
                 FOR EACH ROW
                 WHEN NEW.is_instance = 1 AND NEW.organisation_id IS NOT NULL
                 BEGIN
                     SELECT RAISE(ABORT, \'is_instance requires null organisation_id\');
                 END',
            );

            return;
        }

        // MySQL / MariaDB store booleans as 0/1; PostgreSQL accepts both forms.
        $expression = in_array($driver, ['mysql', 'mariadb'], true)
            ? '(is_instance = 0 OR organisation_id IS NULL)'
            : '(is_instance = false OR organisation_id IS NULL)';

        DB::statement(
            "ALTER TABLE user_groups ADD CONSTRAINT user_groups_instance_org_xor CHECK {$expression}",
        );
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Laravel\AccessSchema;
use Qiick\Access\Laravel\MigrationSettings;

/*
 * Package-owned principals table (design §15.2 / §15.3). The table name is
 * fixed. user_id and created_by reference access.users_table or the auth users
 * provider model table (default "users"). organisation_id has no foreign key
 * here: the product adds it to its own organisations table, restrict on
 * delete (MySQL and MariaDB refuse CASCADE or SET NULL on a base column of a
 * stored generated column), then calls AccessSchema::ensureConstraints().
 * Products generate ids as UUID v7 in the model.
 *
 * user_id is ON DELETE RESTRICT (decision 2026-11-24): erasure is a job,
 * never a cascade, and PrincipalDirectory::eraseUser() unlinks the
 * principals before the user row goes. It also lets MySQL and MariaDB accept
 * user_id in the generated column and the CHECK below, which they refuse
 * next to an ON DELETE SET NULL foreign key.
 *
 * Two stored generated columns keep the rules free of NULL holes on every
 * engine (uniqueness never relies on NULL, core hardening):
 * - organisation_key is the organisation id or '' at instance level, so
 *   UNIQUE (organisation_key, user_id) allows one principal per user and
 *   organisation and one per user at instance level. Rows without a user
 *   (services, agents) are not limited;
 * - instance_type is the type of a row with neither organisation nor user,
 *   unique when organisations are required: instance principals are
 *   singletons.
 *
 * When access.principals.organisation_required is true, AccessSchema adds the
 * organisation rule (a CHECK, triggers on SQLite). The config is read when
 * this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $usersTable = MigrationSettings::usersTable();
        $isOrganisationRequired = MigrationSettings::isOrganisationRequired();
        // Validates the instance types before anything is created.
        MigrationSettings::instanceTypes();
        $driver = Schema::getConnection()->getDriverName();
        // The key is text on every engine: MariaDB's native uuid type refuses ''.
        $organisationIdText = match ($driver) {
            'pgsql' => 'organisation_id::text',
            'mysql', 'mariadb' => 'CAST(organisation_id AS CHAR(36))',
            default => 'organisation_id',
        };

        Schema::create('principals', function (Blueprint $table) use ($usersTable, $isOrganisationRequired, $organisationIdText): void {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            $table->foreignId('user_id')->nullable()->index()->constrained($usersTable)->restrictOnDelete();
            $table->uuid('organisation_id')->nullable()->index();
            $table->uuid('owner_principal_id')->nullable()->index();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->index()->constrained($usersTable)->nullOnDelete();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->string('deactivation_reason', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->string('organisation_key', 36)->storedAs("COALESCE({$organisationIdText}, '')");
            $table->string('instance_type', 32)->nullable()
                ->storedAs('CASE WHEN organisation_id IS NULL AND user_id IS NULL THEN type END');

            $table->index(['organisation_id', 'type']);
            $table->unique(['organisation_key', 'user_id'], 'principals_membership_unique');

            if ($isOrganisationRequired) {
                $table->unique('instance_type', 'principals_instance_singleton');
            }
        });

        // After the create: PostgreSQL needs the primary key before a self-reference.
        Schema::table('principals', function (Blueprint $table): void {
            $table->foreign('owner_principal_id')->references('id')->on('principals')->nullOnDelete();
        });

        AccessSchema::ensureConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('principals');
    }
};

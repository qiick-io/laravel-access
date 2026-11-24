<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Laravel\MigrationSettings;

/*
 * Package-owned principals table (design §15.2 / §15.3). The table name is
 * fixed. user_id and created_by reference access.users_table or the auth users
 * provider model table (default "users"). organisation_id has no foreign key
 * here: the product adds it to its own organisations table, restrict on
 * delete. Products generate ids as UUID v7 in the model.
 *
 * Two stored generated columns keep the rules free of NULL holes on every
 * engine (uniqueness never relies on NULL, core hardening):
 * - organisation_key is the organisation id or '' at instance level, so
 *   UNIQUE (organisation_key, user_id) allows one principal per user and
 *   organisation and one per user at instance level. Rows without a user
 *   (services, agents) are not limited;
 * - instance_type is the type of an organisation-less row, unique when
 *   organisations are required: instance principals are singletons. It does
 *   not read user_id (the design's `AND user_id IS NULL`): MySQL refuses an ON
 *   DELETE SET NULL foreign key on a base column of a stored generated column.
 *   The CHECK below already forces instance rows to have no user, so the
 *   index covers the same rows.
 *
 * The organisation foreign key a product adds must therefore be restrict
 * (as design §15.5 decides), never CASCADE or SET NULL, on MySQL / MariaDB.
 *
 * For the same reason MySQL and MariaDB refuse user_id in a CHECK, so there
 * the organisation rule omits "instance types have no user"; PostgreSQL and
 * SQLite enforce it, and PrincipalDirectory never gives an instance principal
 * a user on any engine.
 *
 * When access.principals.organisation_required is true, a CHECK (triggers on
 * SQLite) allows a NULL organisation only for access.principals.instance_types,
 * which must also have no user. The config is read when this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $usersTable = MigrationSettings::usersTable();
        $isOrganisationRequired = MigrationSettings::isOrganisationRequired();
        $instanceTypes = MigrationSettings::instanceTypes();
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
            $table->foreignId('user_id')->nullable()->index()->constrained($usersTable)->nullOnDelete();
            $table->uuid('organisation_id')->nullable()->index();
            $table->uuid('owner_principal_id')->nullable()->index();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained($usersTable)->nullOnDelete();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->string('deactivation_reason', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->string('organisation_key', 36)->storedAs("COALESCE({$organisationIdText}, '')");
            $table->string('instance_type', 32)->nullable()
                ->storedAs('CASE WHEN organisation_id IS NULL THEN type END');

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

        if ($isOrganisationRequired) {
            $this->addOrganisationRule($instanceTypes, $driver);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('principals');
    }

    /**
     * Only the declared instance types may lack an organisation, and those
     * must also lack a user (design §15.3, C2). SQLite has no ALTER ADD
     * CONSTRAINT, so it gets INSERT/UPDATE triggers as for user_groups (C75).
     *
     * @param  list<string>  $instanceTypes  validated plain type names
     */
    private function addOrganisationRule(array $instanceTypes, string $driver): void
    {
        if ($driver !== 'sqlite') {
            $canCheckUser = ! in_array($driver, ['mysql', 'mariadb'], true);
            DB::statement("ALTER TABLE principals ADD CONSTRAINT principals_organisation_rule CHECK ({$this->organisationRule($instanceTypes, '', $canCheckUser)})");

            return;
        }

        foreach (['INSERT', 'UPDATE'] as $event) {
            $triggerName = 'principals_organisation_rule_'.strtolower($event);

            DB::statement(
                "CREATE TRIGGER {$triggerName}
                 BEFORE {$event} ON principals
                 FOR EACH ROW
                 WHEN NOT ({$this->organisationRule($instanceTypes, 'NEW.', true)})
                 BEGIN
                     SELECT RAISE(ABORT, 'principal organisation rule violated');
                 END",
            );
        }
    }

    /**
     * @param  list<string>  $instanceTypes
     * @param  string  $row  column prefix: '' in a CHECK, 'NEW.' in a trigger
     * @param  bool  $canCheckUser  false where the engine refuses user_id in a CHECK
     */
    private function organisationRule(array $instanceTypes, string $row, bool $canCheckUser): string
    {
        if ($instanceTypes === []) {
            return "{$row}organisation_id IS NOT NULL";
        }

        $typeList = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $instanceTypes));

        $userIsNull = $canCheckUser ? " AND {$row}user_id IS NULL" : '';

        return "({$row}type IN ({$typeList}) AND {$row}organisation_id IS NULL{$userIsNull})"
            ." OR ({$row}type NOT IN ({$typeList}) AND {$row}organisation_id IS NOT NULL)";
    }
};

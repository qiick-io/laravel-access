<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The table rules the package migrations add beyond columns and indexes, as
 * a public, idempotent helper (review B2).
 *
 * SQLite has no ALTER TABLE ADD CONSTRAINT, so the rules are triggers there,
 * and SQLite drops every trigger of a table when Laravel rebuilds it (adding
 * a foreign key, changing a column). AccessServiceProvider therefore calls
 * ensureConstraints() after every migrate run (MigrationsEnded), and product
 * migrations may call it themselves; on the other engines the rules are
 * CHECK constraints that survive, and the call only confirms them.
 *
 * - user_groups: is_instance only with a NULL organisation (AC17 / C75);
 * - principals, when access.principals.organisation_required is true: only
 *   the access.principals.instance_types may lack an organisation, and those
 *   must also lack a user (design §15.3, C2).
 *
 * Both rules read the config when they run, as the migrations do: on SQLite a
 * config changed after migrating gives recreated triggers the new rule, while
 * the CHECK on the other engines keeps the rule it was created with. Keep the
 * config unchanged after migrating.
 *
 * Each rule applies only to a table the package owns (access.migrations.groups
 * / access.migrations.principals true); a product's own user_groups is never
 * touched. Rules are ensured on the given (default: the default) connection.
 *
 * Connections with a table prefix are not supported: the rule SQL and the
 * catalogue lookups use the bare table names.
 *
 * @api
 */
final class AccessSchema
{
    /**
     * @param  Connection|null  $connection  default: the default connection
     */
    public static function ensureConstraints(?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        $schema = $connection->getSchemaBuilder();

        // Only tables the package owns: a product that keeps its own
        // user_groups (access.migrations.groups = false) never gets the
        // package rule added to it (review R6).
        if (config('access.migrations.groups', true) === true && $schema->hasTable('user_groups')) {
            $isNumericBoolean = $connection->getDriverName() !== 'pgsql';

            self::ensureRule(
                $connection,
                'user_groups',
                'user_groups_instance_org_xor',
                // PostgreSQL has a real boolean; the other engines store 0/1.
                static fn (string $row): string => "{$row}is_instance = ".($isNumericBoolean ? '0' : 'false')." OR {$row}organisation_id IS NULL",
                'is_instance requires null organisation_id',
            );
        }

        if (config('access.migrations.principals', true) === true && $schema->hasTable('principals') && MigrationSettings::isOrganisationRequired()) {
            $instanceTypes = MigrationSettings::instanceTypes();

            self::ensureRule(
                $connection,
                'principals',
                'principals_organisation_rule',
                static fn (string $row): string => self::principalRule($instanceTypes, $row),
                'principal organisation rule violated',
            );
        }
    }

    /**
     * @param  \Closure(string): string  $rule  the rule, given the column prefix ('' in a CHECK, 'NEW.' in a trigger)
     */
    private static function ensureRule(Connection $connection, string $table, string $name, \Closure $rule, string $message): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                $connection->statement(
                    "CREATE TRIGGER IF NOT EXISTS {$name}_{$suffix}"
                    ." BEFORE {$event} ON {$table} FOR EACH ROW WHEN NOT ({$rule('NEW.')})"
                    ." BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                );
            }

            return;
        }

        if (! self::hasCheckConstraint($connection, $table, $name)) {
            $connection->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule('')})");
        }
    }

    private static function hasCheckConstraint(Connection $connection, string $table, string $name): bool
    {
        if ($connection->getDriverName() === 'pgsql') {
            // By table too: another schema may hold a constraint of that name (review N13).
            return $connection->table('pg_constraint')->where('conname', $name)->whereRaw('conrelid = ?::regclass', [$table])->exists();
        }

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    /**
     * @param  list<string>  $instanceTypes  validated plain type names
     */
    private static function principalRule(array $instanceTypes, string $row): string
    {
        if ($instanceTypes === []) {
            return "{$row}organisation_id IS NOT NULL";
        }

        $typeList = implode(', ', array_map(static fn (string $type): string => "'{$type}'", $instanceTypes));

        return "({$row}type IN ({$typeList}) AND {$row}organisation_id IS NULL AND {$row}user_id IS NULL)"
            ." OR ({$row}type NOT IN ({$typeList}) AND {$row}organisation_id IS NOT NULL)";
    }
}

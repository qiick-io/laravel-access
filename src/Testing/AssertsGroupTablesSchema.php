<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conformance helper AC17 / C75: the three fixed group tables match standards
 * review §2.4–2.6 (columns, nullability, indexes, FK cascade) plus
 * organisation_id / is_instance with the XOR check (is_instance true only
 * when organisation_id is null), and group names unique per organisation
 * (AC-a). Products that adopt the tables (including externa with
 * access.migrations.groups = false) call assertShape() so their schema
 * cannot drift from the package.
 *
 * @api
 */
final class AssertsGroupTablesSchema
{
    /**
     * @param  string|null  $organisationId  an existing organisation, for a product whose
     *                                       user_groups.organisation_id references its own table
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?string $organisationId = null,
    ) {}

    /**
     * Throws RuntimeException when any of user_groups, user_group_user or
     * user_group_role is missing or violates §2.4–2.6.
     */
    public function assertShape(): void
    {
        $this->assertUserGroups();
        $this->assertUserGroupUser();
        $this->assertUserGroupRole();
    }

    private function assertUserGroups(): void
    {
        $this->requireTable('user_groups');
        $this->requireColumns('user_groups', [
            'id',
            'public_id',
            'name',
            'description',
            'metadata',
            'organisation_id',
            'is_instance',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
        $this->requireNotNullable('user_groups', 'id');
        $this->requireNotNullable('user_groups', 'public_id');
        $this->requireNotNullable('user_groups', 'name');
        $this->requireNullable('user_groups', 'description');
        $this->requireNullable('user_groups', 'metadata');
        $this->requireNullable('user_groups', 'organisation_id');
        $this->requireNotNullable('user_groups', 'is_instance');
        $this->requireNullable('user_groups', 'deleted_at');
        $this->requireUniqueCovering('user_groups', 'public_id');
        $this->requireGroupNameUniqueness();
        $this->requireIndexCovering('user_groups', 'organisation_id');
        $this->requireInstanceOrganisationXorConstraint();
    }

    /**
     * AC-a: a group name is unique within its organisation and among the
     * groups without one. The package indexes (organisation_key, name), where
     * organisation_key is the organisation id or ''; a product without
     * organisations (externa 1.x, its own tables) may keep a global unique
     * name. Either way a rolled-back insert proves that two organisation-less
     * groups cannot share a name: a unique (organisation_id, name) would pass
     * the index check and still accept them, because NULLs are distinct.
     */
    private function requireGroupNameUniqueness(): void
    {
        $uniqueColumnLists = array_column(array_filter(
            $this->connection->getSchemaBuilder()->getIndexes('user_groups'),
            static fn (array $index): bool => $index['unique'],
        ), 'columns');

        if (! in_array(['organisation_key', 'name'], $uniqueColumnLists, true) && ! in_array(['name'], $uniqueColumnLists, true)) {
            throw new RuntimeException(
                'AC-a: expected a unique index on [user_groups.organisation_key,name] (or on [user_groups.name] in a product without organisations).',
            );
        }

        $this->probeRefusal(
            $this->probeGroupRow('__ac_a_duplicate_name__', null, false),
            $this->probeGroupRow('__ac_a_duplicate_name__', null, false),
            'AC-a: two groups without an organisation were saved with the same name.',
        );
    }

    /**
     * is_instance=true is allowed only with organisation_id NULL. Proved by a
     * rolled-back insert so SQLite triggers and SQL CHECK constraints both count.
     */
    private function requireInstanceOrganisationXorConstraint(): void
    {
        // Control and probe share one organisation and differ only in
        // is_instance (and the name), so no FK or unique index can refuse
        // the probe for the rule. INSERT and UPDATE are both proved.
        $organisationId = $this->organisationId ?? (string) Str::uuid();
        $failure = 'AC17/C75: expected CHECK that is_instance=true only when organisation_id IS NULL on user_groups.';

        $this->probeRefusal(
            $this->probeGroupRow('__ac17_instance_org_control__', $organisationId, false),
            $this->probeGroupRow('__ac17_instance_org_xor__', $organisationId, true),
            $failure,
        );
        $this->probeRefusal(
            $this->probeGroupRow('__ac17_instance_org_control__', $organisationId, false),
            ['is_instance' => true],
            $failure,
        );
    }

    /**
     * Inside a transaction that is always rolled back: the valid control row
     * must be saved, so a failure of the probe row is the rule's refusal and
     * not an unrelated constraint of the product's table.
     *
     * @param  array<string, mixed>  $controlRow  a row the schema must accept
     * @param  array<string, mixed>  $probeRow  a row the rule must refuse, or the columns of an update of the control row
     *
     * @throws RuntimeException with $failure when the probe row is saved, or when the control row is refused
     */
    private function probeRefusal(array $controlRow, array $probeRow, string $failure): void
    {
        $this->connection->beginTransaction();

        try {
            try {
                $this->connection->table('user_groups')->insert($controlRow);
            } catch (QueryException $exception) {
                throw new RuntimeException("AC17: user_groups refused a valid probe row, so the probe proves nothing (with an organisation FK, pass an existing organisation id): {$exception->getMessage()}", previous: $exception);
            }

            try {
                // A full row is inserted; a partial one updates the control row.
                isset($probeRow['public_id'])
                    ? $this->connection->table('user_groups')->insert($probeRow)
                    : $this->connection->table('user_groups')->where('public_id', $controlRow['public_id'])->update($probeRow);
            } catch (QueryException) {
                return;
            }

            throw new RuntimeException($failure);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * A user_groups row for the rolled-back probes, every column set that a
     * product table may declare NOT NULL.
     *
     * @return array<string, mixed>
     */
    private function probeGroupRow(string $name, ?string $organisationId, bool $isInstance): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'description' => null,
            'metadata' => null,
            'organisation_id' => $organisationId,
            'is_instance' => $isInstance,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function assertUserGroupUser(): void
    {
        $this->requireTable('user_group_user');
        $this->requireColumns('user_group_user', [
            'user_group_id',
            'user_id',
            'expires_at',
            'created_at',
            'updated_at',
        ]);
        $this->forbidColumn('user_group_user', 'id');
        $this->requireNotNullable('user_group_user', 'user_group_id');
        $this->requireNotNullable('user_group_user', 'user_id');
        $this->requireNullable('user_group_user', 'expires_at');
        $this->requireUniqueCovering('user_group_user', 'user_group_id', 'user_id');
        $this->requireIndexCovering('user_group_user', 'user_id');
        $this->requireIndexCovering('user_group_user', 'expires_at');
        $this->requireForeignKeyCascade('user_group_user', 'user_group_id', 'user_groups');
        $this->requireForeignKeyCascade('user_group_user', 'user_id', $this->usersTable());
    }

    private function assertUserGroupRole(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');
        $rolesTable = is_string($rolesTable) && $rolesTable !== '' ? $rolesTable : 'roles';

        $this->requireTable('user_group_role');
        $this->requireColumns('user_group_role', [
            'user_group_id',
            'role_id',
            'created_at',
            'updated_at',
        ]);
        $this->forbidColumn('user_group_role', 'id');
        $this->requireNotNullable('user_group_role', 'user_group_id');
        $this->requireNotNullable('user_group_role', 'role_id');
        $this->requireUniqueCovering('user_group_role', 'user_group_id', 'role_id');
        $this->requireIndexCovering('user_group_role', 'role_id');
        $this->requireForeignKeyCascade('user_group_role', 'user_group_id', 'user_groups');
        $this->requireForeignKeyCascade('user_group_role', 'role_id', $rolesTable);
    }

    private function usersTable(): string
    {
        $configured = config('access.users_table');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $modelClass = config('auth.providers.users.model');
        if (is_string($modelClass) && class_exists($modelClass)) {
            $model = new $modelClass;
            if ($model instanceof Model) {
                return $model->getTable();
            }
        }

        return 'users';
    }

    private function requireTable(string $table): void
    {
        if (! Schema::connection($this->connection->getName())->hasTable($table)) {
            throw new RuntimeException("AC17: expected table [{$table}] to exist (§2.4–2.6).");
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function requireColumns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::connection($this->connection->getName())->hasColumn($table, $column)) {
                throw new RuntimeException(
                    "AC17: expected column [{$table}.{$column}] (§2.4–2.6).",
                );
            }
        }
    }

    private function forbidColumn(string $table, string $column): void
    {
        if (Schema::connection($this->connection->getName())->hasColumn($table, $column)) {
            throw new RuntimeException(
                "AC17: column [{$table}.{$column}] must be absent (no surrogate pivot id, §2.5–2.6).",
            );
        }
    }

    private function requireNullable(string $table, string $column): void
    {
        if (! $this->isNullable($table, $column)) {
            throw new RuntimeException(
                "AC17: column [{$table}.{$column}] must be nullable (§2.4–2.6).",
            );
        }
    }

    private function requireNotNullable(string $table, string $column): void
    {
        if ($this->isNullable($table, $column)) {
            throw new RuntimeException(
                "AC17: column [{$table}.{$column}] must not be nullable (§2.4–2.6).",
            );
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach ($this->connection->getSchemaBuilder()->getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition['nullable'];
            }
        }

        throw new RuntimeException(
            "AC17: column [{$table}.{$column}] missing while checking nullability.",
        );
    }

    private function requireUniqueCovering(string $table, string ...$columns): void
    {
        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return;
            }
        }

        throw new RuntimeException(
            'AC17: expected unique index on ['.$table.'.'.implode(',', $columns).'] (§2.4–2.6).',
        );
    }

    private function requireIndexCovering(string $table, string ...$columns): void
    {
        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return;
            }
        }

        throw new RuntimeException(
            'AC17: expected index on ['.$table.'.'.implode(',', $columns).'] (§2.4–2.6).',
        );
    }

    private function requireForeignKeyCascade(string $table, string $column, string $foreignTable): void
    {
        foreach ($this->connection->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] !== [$column]) {
                continue;
            }

            if ($foreignKey['foreign_table'] !== $foreignTable) {
                throw new RuntimeException(
                    "AC17: [{$table}.{$column}] must reference [{$foreignTable}], got [{$foreignKey['foreign_table']}].",
                );
            }

            // Laravel's schema processors report the action in lower case on every engine.
            $onDelete = $foreignKey['on_delete'] ?? 'no action';

            if ($onDelete !== 'cascade') {
                throw new RuntimeException(
                    "AC17: [{$table}.{$column}] FK onDelete must be cascade, got [{$onDelete}].",
                );
            }

            return;
        }

        throw new RuntimeException(
            "AC17: expected cascade FK [{$table}.{$column}] → [{$foreignTable}] (§2.5–2.6).",
        );
    }
}

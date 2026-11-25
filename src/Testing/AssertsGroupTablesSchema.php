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
    public function __construct(
        private readonly Connection $connection,
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
            $this->indexColumnLists('user_groups'),
            static fn (array $index): bool => $index['unique'],
        ), 'columns');

        if (! in_array(['organisation_key', 'name'], $uniqueColumnLists, true) && ! in_array(['name'], $uniqueColumnLists, true)) {
            throw new RuntimeException(
                'AC-a: expected a unique index on [user_groups.organisation_key,name] (or on [user_groups.name] in a product without organisations).',
            );
        }

        $this->connection->beginTransaction();

        try {
            foreach ([1, 2] as $attempt) {
                $this->connection->table('user_groups')->insert([
                    'public_id' => (string) Str::uuid(),
                    'name' => '__ac_a_duplicate_name__',
                    'organisation_id' => null,
                    'is_instance' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (QueryException) {
            $this->connection->rollBack();

            return;
        }

        $this->connection->rollBack();

        throw new RuntimeException('AC-a: two groups without an organisation were saved with the same name.');
    }

    /**
     * is_instance=true is allowed only with organisation_id NULL. Proved by a
     * rolled-back insert so SQLite triggers and SQL CHECK constraints both count.
     */
    private function requireInstanceOrganisationXorConstraint(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->table('user_groups')->insert([
                'public_id' => (string) Str::uuid(),
                'name' => '__ac17_instance_org_xor__',
                'description' => null,
                'metadata' => null,
                'organisation_id' => (string) Str::uuid(),
                'is_instance' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->connection->rollBack();

            throw new RuntimeException(
                'AC17/C75: expected CHECK that is_instance=true only when organisation_id IS NULL on user_groups.',
            );
        } catch (QueryException) {
            $this->connection->rollBack();
        }
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
            if (($definition['name'] ?? null) === $column) {
                return (bool) ($definition['nullable'] ?? false);
            }
        }

        throw new RuntimeException(
            "AC17: column [{$table}.{$column}] missing while checking nullability.",
        );
    }

    private function requireUniqueCovering(string $table, string ...$columns): void
    {
        $wanted = array_values($columns);

        foreach ($this->indexColumnLists($table) as $index) {
            if (! $index['unique']) {
                continue;
            }

            if ($index['columns'] === $wanted) {
                return;
            }
        }

        throw new RuntimeException(
            'AC17: expected unique index on ['.$table.'.'.implode(',', $wanted).'] (§2.4–2.6).',
        );
    }

    private function requireIndexCovering(string $table, string ...$columns): void
    {
        $wanted = array_values($columns);

        foreach ($this->indexColumnLists($table) as $index) {
            if ($index['columns'] === $wanted) {
                return;
            }
        }

        throw new RuntimeException(
            'AC17: expected index on ['.$table.'.'.implode(',', $wanted).'] (§2.4–2.6).',
        );
    }

    /**
     * @return list<array{unique: bool, columns: list<string>}>
     */
    private function indexColumnLists(string $table): array
    {
        $normalized = [];

        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if (! is_array($index)) {
                continue;
            }

            $columns = $index['columns'] ?? null;
            if (! is_array($columns)) {
                continue;
            }

            $columnNames = [];
            foreach ($columns as $columnName) {
                if (is_string($columnName)) {
                    $columnNames[] = $columnName;
                }
            }

            $normalized[] = [
                'unique' => (bool) ($index['unique'] ?? false),
                'columns' => $columnNames,
            ];
        }

        return $normalized;
    }

    private function requireForeignKeyCascade(string $table, string $column, string $foreignTable): void
    {
        foreach ($this->foreignKeyDescriptors($table) as $foreignKey) {
            if ($foreignKey['columns'] !== [$column]) {
                continue;
            }

            if ($foreignKey['foreign_table'] !== $foreignTable) {
                throw new RuntimeException(
                    "AC17: [{$table}.{$column}] must reference [{$foreignTable}], got [{$foreignKey['foreign_table']}].",
                );
            }

            if ($foreignKey['on_delete'] !== 'cascade') {
                throw new RuntimeException(
                    "AC17: [{$table}.{$column}] FK onDelete must be cascade, got [{$foreignKey['on_delete']}].",
                );
            }

            return;
        }

        throw new RuntimeException(
            "AC17: expected cascade FK [{$table}.{$column}] → [{$foreignTable}] (§2.5–2.6).",
        );
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, on_delete: string}>
     */
    private function foreignKeyDescriptors(string $table): array
    {
        $normalized = [];

        foreach ($this->connection->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
            if (! is_array($foreignKey)) {
                continue;
            }

            $columns = $foreignKey['columns'] ?? null;
            if (! is_array($columns)) {
                continue;
            }

            $columnNames = [];
            foreach ($columns as $columnName) {
                if (is_string($columnName)) {
                    $columnNames[] = $columnName;
                }
            }

            $referenced = $foreignKey['foreign_table'] ?? null;
            $onDelete = $foreignKey['on_delete'] ?? null;

            if (! is_string($referenced) || ! is_string($onDelete)) {
                continue;
            }

            $normalized[] = [
                'columns' => $columnNames,
                'foreign_table' => $referenced,
                'on_delete' => strtolower($onDelete),
            ];
        }

        return $normalized;
    }
}

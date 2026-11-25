<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conformance helper A4: the product-owned columns on Spatie's roles table
 * (docs/reference-schema.md, standards review §2.1), so every product
 * asserts the same shape instead of writing its own test. Run it on a
 * freshly migrated database; the team column is AssertsSpatieTeamSchema's.
 *
 * - public_id is required everywhere: not null, with a unique index of its
 *   own (P3; role names can be renamed, audit entries carry this id).
 * - is_system, is_assignable, description and metadata are checked where a
 *   product has them (excubia has neither flag): the flags not null,
 *   defaulting to false and true, which a rolled-back insert that omits
 *   them proves on every engine; description and metadata nullable.
 *
 * The probe insert sets only name, guard_name and public_id, so a product
 * column outside the reference that is NOT NULL without a default fails it.
 *
 * @api
 */
final class AssertsRolesSchema
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @throws RuntimeException naming the first column that differs from the reference
     */
    public function assertShape(): void
    {
        $rolesTable = $this->rolesTable();
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($rolesTable)) {
            throw new RuntimeException("A4: expected table [{$rolesTable}].");
        }

        $nullableByColumn = array_column($schema->getColumns($rolesTable), 'nullable', 'name');

        if (! array_key_exists('public_id', $nullableByColumn)) {
            throw new RuntimeException("A4: expected column [{$rolesTable}.public_id] (P3).");
        }

        foreach (['public_id' => false, 'is_system' => false, 'is_assignable' => false, 'description' => true, 'metadata' => true] as $column => $isNullable) {
            if (array_key_exists($column, $nullableByColumn) && $nullableByColumn[$column] !== $isNullable) {
                $rule = $isNullable ? 'must be nullable' : 'must not be nullable';

                throw new RuntimeException("A4: column [{$rolesTable}.{$column}] {$rule}.");
            }
        }

        $uniqueColumnLists = array_column(array_filter(
            $schema->getIndexes($rolesTable),
            static fn (array $index): bool => $index['unique'],
        ), 'columns');

        if (! in_array(['public_id'], $uniqueColumnLists, true)) {
            throw new RuntimeException("A4: expected a unique index on [{$rolesTable}.public_id] (P3).");
        }

        $this->assertFlagDefaults($rolesTable, array_keys($nullableByColumn));
    }

    /**
     * Inserts a role that omits both flags and reads back what the database
     * filled in, inside a transaction that is always rolled back.
     *
     * @param  list<string>  $columns
     */
    private function assertFlagDefaults(string $rolesTable, array $columns): void
    {
        $expectedDefaults = array_intersect_key(['is_system' => false, 'is_assignable' => true], array_flip($columns));

        if ($expectedDefaults === []) {
            return;
        }

        $this->connection->beginTransaction();

        try {
            $publicId = Str::uuid7()->toString();
            $this->connection->table($rolesTable)->insert(['name' => '__a4_flag_defaults__', 'guard_name' => 'web', 'public_id' => $publicId]);
            $probeRole = (array) $this->connection->table($rolesTable)->where('public_id', $publicId)->first();
        } finally {
            $this->connection->rollBack();
        }

        foreach ($expectedDefaults as $column => $expectedDefault) {
            if ((bool) $probeRole[$column] !== $expectedDefault) {
                $expected = $expectedDefault ? 'true' : 'false';

                throw new RuntimeException("A4: [{$rolesTable}.{$column}] must default to {$expected}.");
            }
        }
    }

    private function rolesTable(): string
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        return is_string($rolesTable) && $rolesTable !== '' ? $rolesTable : 'roles';
    }
}

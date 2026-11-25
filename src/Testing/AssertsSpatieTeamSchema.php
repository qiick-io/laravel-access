<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use RuntimeException;
use stdClass;

/**
 * Conformance helper A3: when Spatie teams are on, team_foreign_key must be
 * organisation_id and the three team columns must be uuid (fleet core schema).
 * Products call assertWhenTeamsEnabled() from their conformance suite; run it
 * on PostgreSQL so a wrong column type fails closed.
 *
 * @api
 */
final class AssertsSpatieTeamSchema
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Connection $connection,
    ) {}

    /**
     * No-op when teams are off. Throws RuntimeException when teams are on and
     * the config or schema violates the fleet rule.
     */
    public function assertWhenTeamsEnabled(): void
    {
        if ($this->config->get('permission.teams') !== true) {
            return;
        }

        $teamForeignKey = $this->config->get('permission.column_names.team_foreign_key');
        if ($teamForeignKey !== 'organisation_id') {
            throw new RuntimeException(
                'permission.column_names.team_foreign_key must be organisation_id when teams are enabled (A3).',
            );
        }

        $schema = $this->schema();

        foreach ($this->teamScopedTableNames() as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException(
                    "Spatie table [{$table}] is missing; cannot assert organisation_id uuid (A3).",
                );
            }

            if (! $schema->hasColumn($table, 'organisation_id')) {
                throw new RuntimeException(
                    "Table [{$table}] must have an organisation_id column when teams are enabled (A3).",
                );
            }

            $this->assertOrganisationIdIsUuid($table);
        }
    }

    /**
     * @return list<string>
     */
    private function teamScopedTableNames(): array
    {
        $tableNames = $this->config->get('permission.table_names', []);
        $defaults = [
            'roles' => 'roles',
            'model_has_roles' => 'model_has_roles',
            'model_has_permissions' => 'model_has_permissions',
        ];

        $resolved = [];
        foreach ($defaults as $key => $fallback) {
            $candidate = is_array($tableNames) ? ($tableNames[$key] ?? $fallback) : $fallback;
            if (! is_string($candidate) || $candidate === '') {
                throw new RuntimeException(
                    "permission.table_names.{$key} must be a non-empty string when teams are enabled (A3).",
                );
            }

            $resolved[] = $candidate;
        }

        return $resolved;
    }

    private function assertOrganisationIdIsUuid(string $table): void
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            return;
        }

        $row = $this->connection->selectOne(
            'select data_type from information_schema.columns'
            .' where table_schema = current_schema()'
            .' and table_name = ? and column_name = ?',
            [$table, 'organisation_id'],
        );

        $dataType = $this->readDataType($row);
        if ($dataType !== 'uuid') {
            throw new RuntimeException(
                "Column [{$table}.organisation_id] must be uuid on PostgreSQL when teams are enabled (A3), got [{$dataType}].",
            );
        }
    }

    private function schema(): SchemaBuilder
    {
        return $this->connection->getSchemaBuilder();
    }

    private function readDataType(mixed $row): string
    {
        if ($row instanceof stdClass && property_exists($row, 'data_type') && is_string($row->data_type)) {
            return $row->data_type;
        }

        if (is_array($row) && isset($row['data_type']) && is_string($row['data_type'])) {
            return $row['data_type'];
        }

        return '';
    }
}

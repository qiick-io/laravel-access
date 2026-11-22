<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Conformance helper A3: when Spatie teams are on, team_foreign_key must be
 * organisation_id and the three team columns must be uuid (fleet core schema).
 * Products call assertWhenTeamsEnabled() from their conformance suite; run it
 * on PostgreSQL so a wrong column type fails closed.
 */
final class AssertsSpatieTeamSchema
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConnectionInterface $connection,
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

        $tableNames = $this->config->get('permission.table_names', []);
        $rolesTable = is_array($tableNames) ? ($tableNames['roles'] ?? 'roles') : 'roles';
        $modelHasRoles = is_array($tableNames) ? ($tableNames['model_has_roles'] ?? 'model_has_roles') : 'model_has_roles';
        $modelHasPermissions = is_array($tableNames)
            ? ($tableNames['model_has_permissions'] ?? 'model_has_permissions')
            : 'model_has_permissions';

        foreach ([$rolesTable, $modelHasRoles, $modelHasPermissions] as $table) {
            if (! is_string($table) || $table === '' || ! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Spatie table [{$table}] is missing; cannot assert organisation_id uuid (A3).",
                );
            }

            if (! Schema::hasColumn($table, 'organisation_id')) {
                throw new RuntimeException(
                    "Table [{$table}] must have an organisation_id column when teams are enabled (A3).",
                );
            }

            $this->assertOrganisationIdIsUuid($table);
        }
    }

    private function assertOrganisationIdIsUuid(string $table): void
    {
        $driver = $this->connection->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        $row = $this->connection->selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
            [$table, 'organisation_id'],
        );

        $dataType = is_object($row) && isset($row->data_type) ? (string) $row->data_type : '';
        if ($dataType !== 'uuid') {
            throw new RuntimeException(
                "Column [{$table}.organisation_id] must be uuid on PostgreSQL when teams are enabled (A3), got [{$dataType}].",
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Qiick\Access\Laravel\MigrationSettings;
use RuntimeException;

/**
 * Conformance helper for the principals table (design §15.6): run it on a
 * freshly migrated database so config and schema cannot drift silently (P9).
 *
 * assertShape() checks the columns. assertConstraints() proves with
 * rolled-back inserts that the database itself refuses what the design
 * forbids, so SQL CHECKs, SQLite triggers and unique indexes all count:
 * - a second principal for the same user and organisation, and for the same
 *   user at instance level when organisations are optional (P1);
 * - an organisation-less principal of a non-instance type when
 *   access.principals.organisation_required is true (P2);
 * - a second principal of each declared instance type (P3).
 *
 * The user rows the probes need come from $createUserId (a product factory),
 * inside the same rolled-back transaction.
 *
 * @api
 */
final class AssertsPrincipalsSchema
{
    /**
     * @param  Closure(): (int|string)  $createUserId  creates a user row and returns its id
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $createUserId,
    ) {}

    /**
     * @throws RuntimeException when the table or a design column is missing
     */
    public function assertShape(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable('principals')) {
            throw new RuntimeException('§15.6: expected table [principals] to exist.');
        }

        foreach ([
            'id', 'type', 'user_id', 'organisation_id', 'owner_principal_id', 'name', 'created_by', 'expires_at',
            'deactivated_at', 'deactivation_reason', 'metadata', 'created_at', 'updated_at', 'organisation_key', 'instance_type',
        ] as $column) {
            if (! $schema->hasColumn('principals', $column)) {
                throw new RuntimeException("§15.6: expected column [principals.{$column}].");
            }
        }
    }

    /**
     * @throws RuntimeException when the database accepts a forbidden row
     */
    public function assertConstraints(): void
    {
        $isOrganisationRequired = MigrationSettings::isOrganisationRequired();
        $organisationId = Str::uuid()->toString();

        $this->expectRefused(
            'P1: the database accepted a second principal for the same user and organisation.',
            fn () => ['user_id' => ($this->createUserId)(), 'organisation_id' => $organisationId],
            static fn (array $first): array => $first,
        );

        if (! $isOrganisationRequired) {
            $this->expectRefused(
                'P1: the database accepted a second instance-level principal for the same user.',
                fn () => ['user_id' => ($this->createUserId)()],
                static fn (array $first): array => $first,
            );

            return;
        }

        $this->expectRefused(
            'P2: the database accepted a principal without an organisation although access.principals.organisation_required is true.',
            null,
            static fn (): array => [],
        );

        foreach (MigrationSettings::instanceTypes() as $instanceType) {
            $this->expectRefused(
                "P3: the database accepted a second [{$instanceType}] instance principal.",
                static fn () => ['type' => $instanceType],
                static fn (array $first): array => $first,
            );
        }
    }

    /**
     * Inserts an allowed row (if any), then a row that must be refused,
     * inside a transaction that is always rolled back.
     *
     * @param  (Closure(): array<string, mixed>)|null  $allowedRow
     * @param  Closure(array<string, mixed>): array<string, mixed>  $forbiddenRow  built from the allowed row
     */
    private function expectRefused(string $failure, ?Closure $allowedRow, Closure $forbiddenRow): void
    {
        $this->connection->beginTransaction();

        try {
            $first = $allowedRow === null ? [] : $allowedRow();

            if ($allowedRow !== null) {
                $this->insertPrincipal($first);
            }

            try {
                $this->insertPrincipal($forbiddenRow($first));
            } catch (QueryException) {
                return;
            }

            throw new RuntimeException("§15.6 {$failure}");
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertPrincipal(array $attributes): void
    {
        $this->connection->table('principals')->insert([
            'id' => Str::uuid7()->toString(),
            'type' => 'user',
            'name' => '§15.6 probe',
            ...$attributes,
        ]);
    }
}

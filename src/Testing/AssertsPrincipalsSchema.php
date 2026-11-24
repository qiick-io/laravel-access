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
 * - a principal of a declared instance type with a user, and a second
 *   principal of each declared instance type (P2, P3);
 * - deleting a user who still has a principal (user_id is restrict).
 *
 * - deleting an organisation that still has principals, when the product
 *   passes $deleteOrganisation (P6, organisation foreign key restrict).
 *
 * The user and organisation rows the probes need come from the product's
 * factories ($createUserId, $createOrganisationId), inside the same
 * rolled-back transaction, so the probe runs with the organisation foreign
 * key in place. Only integrity refusals count; any other error propagates.
 *
 * @api
 */
final class AssertsPrincipalsSchema
{
    /**
     * @param  Closure(): (int|string)  $createUserId  creates a user row and returns its id
     * @param  (Closure(): string)|null  $createOrganisationId  creates an organisation and returns its id;
     *                                                          required once the organisation foreign key exists
     * @param  (Closure(string): mixed)|null  $deleteOrganisation  deletes an organisation by id, to prove the
     *                                                             restrict foreign key (P6)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $createUserId,
        private readonly ?Closure $createOrganisationId = null,
        private readonly ?Closure $deleteOrganisation = null,
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
     * @throws RuntimeException when the database accepts a forbidden write
     * @throws QueryException when a write fails for a reason that is not an integrity refusal
     */
    public function assertConstraints(): void
    {
        $this->expectRefused('P1: the database accepted a second principal for the same user and organisation.', function (): Closure {
            $row = ['user_id' => ($this->createUserId)(), 'organisation_id' => $this->organisationId()];
            $this->insertPrincipal($row);

            return fn () => $this->insertPrincipal($row);
        });

        $this->expectRefused('erasure: the database deleted a user who still has a principal; principals.user_id must be ON DELETE RESTRICT.', function (): Closure {
            $userId = ($this->createUserId)();
            $this->insertPrincipal(['user_id' => $userId, 'organisation_id' => $this->organisationId()]);

            return fn () => $this->connection->table(MigrationSettings::usersTable())->where('id', $userId)->delete();
        });

        if ($this->deleteOrganisation !== null) {
            $this->expectRefused('P6: the database deleted an organisation that still has principals; the organisation foreign key must be restrict.', function (): Closure {
                $organisationId = $this->organisationId();
                $this->insertPrincipal(['organisation_id' => $organisationId]);

                return fn () => ($this->deleteOrganisation)($organisationId);
            });
        }

        if (! MigrationSettings::isOrganisationRequired()) {
            $this->expectRefused('P1: the database accepted a second instance-level principal for the same user.', function (): Closure {
                $row = ['user_id' => ($this->createUserId)()];
                $this->insertPrincipal($row);

                return fn () => $this->insertPrincipal($row);
            });

            return;
        }

        $this->expectRefused(
            'P2: the database accepted a principal without an organisation although access.principals.organisation_required is true.',
            fn (): Closure => fn () => $this->insertPrincipal([]),
        );

        foreach (MigrationSettings::instanceTypes() as $instanceType) {
            $this->expectRefused("P2: the database accepted a [{$instanceType}] instance principal with a user.", function () use ($instanceType): Closure {
                $userId = ($this->createUserId)();

                return fn () => $this->insertPrincipal(['type' => $instanceType, 'user_id' => $userId]);
            });

            $this->expectRefused("P3: the database accepted a second [{$instanceType}] instance principal.", function () use ($instanceType): Closure {
                $this->insertPrincipal(['type' => $instanceType]);

                return fn () => $this->insertPrincipal(['type' => $instanceType]);
            });
        }
    }

    /**
     * A real organisation where the product passed $createOrganisationId (its
     * organisations foreign key must hold), a random id otherwise.
     */
    private function organisationId(): string
    {
        return $this->createOrganisationId === null ? Str::uuid()->toString() : ($this->createOrganisationId)();
    }

    /**
     * Runs $setUp, then the forbidden write it returns, inside a transaction
     * that is always rolled back. Only an integrity refusal counts: a
     * failure of the set-up or any other database error propagates, so a
     * broken probe can never pass (review N12).
     *
     * @param  Closure(): (Closure(): mixed)  $setUp  prepares the rows and returns the forbidden write
     */
    private function expectRefused(string $failure, Closure $setUp): void
    {
        $this->connection->beginTransaction();

        try {
            $forbiddenWrite = $setUp();

            try {
                $forbiddenWrite();
            } catch (QueryException $exception) {
                if ($this->isIntegrityRefusal($exception)) {
                    return;
                }

                throw $exception;
            }

            throw new RuntimeException("§15.6 {$failure}");
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * SQLSTATE class 23 (integrity constraint violation), MySQL's CHECK
     * violation (3819, reported as HY000), or the package's SQLite triggers.
     */
    private function isIntegrityRefusal(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return (is_string($sqlState) && str_starts_with($sqlState, '23'))
            || ($exception->errorInfo[1] ?? null) === 3819
            || str_contains($exception->getMessage(), 'principal organisation rule violated');
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

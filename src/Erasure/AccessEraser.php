<?php

declare(strict_types=1);

namespace Qiick\Access\Erasure;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Events\SubjectErasedEvent;
use Qiick\Access\Laravel\MigrationSettings;
use Qiick\Access\Principals\EloquentPrincipalDirectory;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Erases the subject's access data on the users connection so the product can
 * commit the final user deletion in the same transaction.
 *
 * @api
 *
 * @phpstan-import-type StoreCounts from ErasureReport
 */
final class AccessEraser
{
    public const string DELETE = 'delete';

    public const string ANONYMISE = 'anonymise';

    public function __construct(
        private readonly EloquentPrincipalDirectory $principals,
        private readonly DatabaseManager $database,
        private readonly EffectivePermissionResolver $permissions,
        private readonly Dispatcher $events,
        private readonly RoleCatalog $roleCatalog,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  list<string>  $publicIds  the subject's principal and credential UUIDs
     * @param  list<string>  $identifiers  free-text markers supplied by the product
     *
     * @throws InvalidArgumentException when the erasure inputs are invalid
     */
    public function erase(
        string $userId,
        string $userPublicId,
        string $mode,
        array $publicIds = [],
        array $identifiers = [],
        ?string $organisationId = null,
        bool $dryRun = false,
    ): ErasureReport {
        if (! in_array($mode, [self::DELETE, self::ANONYMISE], true)
            || ! Str::isUuid($userPublicId)
            || ($organisationId !== null && ! Str::isUuid($organisationId))
            || ! array_is_list($publicIds) || ! array_is_list($identifiers)) {
            throw new InvalidArgumentException('Invalid access erasure arguments.');
        }

        foreach ($publicIds as $publicId) {
            if (! is_string($publicId) || ! Str::isUuid($publicId)) {
                throw new InvalidArgumentException('Invalid access erasure public identifier.');
            }
        }

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('Invalid access erasure free-text identifier.');
            }
        }

        $user = new (MigrationSettings::userModel());

        if (! $user instanceof Authenticatable) {
            throw new LogicException('Access erasure requires an authenticatable Eloquent users model.');
        }

        // Bind numeric keys as integers to avoid a lossy string comparison against bigint.
        $key = Str::isUuid($userId) && $user->getKeyType() === 'string'
            ? strtolower($userId)
            : filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($key === false) {
            throw new InvalidArgumentException('Invalid access erasure user key.');
        }

        // Build the identity for Spatie and the resolver without reading a user row.
        // The product can resume with only its encrypted subject snapshot.
        $user->setRawAttributes([$user->getAuthIdentifierName() => $key]);
        $connection = $this->principals->erasureConnection();

        if ($connection->getName() !== $user->getConnection()->getName() || $connection->getName() !== $this->database->getDefaultConnection()) {
            throw new LogicException('Access erasure requires principals, users, groups and Spatie grants on the default connection.');
        }

        $publicIds = array_map(strtolower(...), $publicIds);
        $organisationId = $organisationId === null ? null : strtolower($organisationId);

        try {
            $stores = $connection->transaction(fn (): array => $this->eraseDatabaseStores($connection, $key, $user->getMorphClass(), $publicIds, $organisationId, $dryRun));
        } catch (QueryException) {
            // Laravel includes SQL bindings in QueryException messages.
            throw new RuntimeException('Access erasure database operation failed.');
        }

        $stores['cache:resolver'] = $this->counts(deleted: $this->permissions->cachedVerdictCount($user));
        $report = new ErasureReport('qiick/laravel-access', $mode, $dryRun, $stores);

        if (! $dryRun) {
            $connection->afterCommit(function () use ($user, $report): void {
                $this->permissions->forget($user);
                $this->events->dispatch(new SubjectErasedEvent($report));
            });
        }

        return $report;
    }

    /**
     * @param  list<string>  $publicIds
     * @return array<string, StoreCounts>
     */
    private function eraseDatabaseStores(Connection $connection, int|string $userId, string $morphClass, array $publicIds, ?string $organisationId, bool $isDryRun): array
    {
        $schema = $connection->getSchemaBuilder();
        $principals = $schema->hasTable('principals')
            ? $this->principals->erasePrincipals($userId, $publicIds, $organisationId, $isDryRun)
            : ['anonymised' => 0, 'tombstones' => 0];
        $stores = [
            'principals' => $this->counts(anonymised: $principals['anonymised'], kept: $principals['tombstones']),
            'cleartext:principals.id' => $this->counts(kept: $principals['tombstones']),
            'user_group_user' => $this->counts(),
        ];

        if ($schema->hasTable('user_group_user')) {
            $memberships = $connection->table('user_group_user')->where('user_id', $userId);

            if ($organisationId !== null) {
                $memberships->whereIn('user_group_id', $connection->table('user_groups')
                    ->where('organisation_id', $organisationId)->where('is_instance', false)->select('id'));
            }

            // The membership is only a link with a required subject foreign key.
            $stores['user_group_user'] = $this->counts(deleted: $isDryRun ? $memberships->count() : $memberships->delete());
        }

        $morphKey = config()->string('permission.column_names.model_morph_key');
        $teamKey = config()->string('permission.column_names.team_foreign_key');
        $hasTeams = config()->boolean('permission.teams');

        foreach (['model_has_roles', 'model_has_permissions'] as $pivot) {
            $table = config()->string("permission.table_names.{$pivot}");
            $stores[$table] = $this->counts();

            if (! $schema->hasTable($table)) {
                continue;
            }

            $grants = $connection->table($table)->where('model_type', $morphClass)->where($morphKey, $userId);

            if ($organisationId !== null) {
                if (! $hasTeams) {
                    $stores[$table] = $this->counts(kept: $grants->count(), reason: 'outside_organisation_scope');

                    continue;
                }

                $grants->where($teamKey, $organisationId);

                if ($pivot === 'model_has_roles') {
                    $grants->whereNotIn($this->registrar->pivotRole, $this->instanceSuperAdminRoles($connection, $teamKey));
                }
            }

            // Spatie uses the subject columns in its primary key. An unlink
            // removes the grant in both modes and preserves the role/permission.
            $stores[$table] = $this->counts(deleted: $isDryRun ? $grants->count() : $grants->delete());
        }

        return $stores;
    }

    /** C30 treats a global super-admin role as instance-wide even when its pivot carries a team. */
    private function instanceSuperAdminRoles(Connection $connection, string $teamKey): Builder
    {
        $roleClass = $this->registrar->getRoleClass();

        if (! is_a($roleClass, Model::class, true)) {
            throw new LogicException('Access erasure requires an Eloquent role model.');
        }

        $role = new $roleClass;

        return $connection->table($role->getTable())->select($role->getKeyName())
            ->whereNull($teamKey)->where('name', $this->roleCatalog->superAdminRoleName());
    }

    /** @return StoreCounts */
    private function counts(int $deleted = 0, int $anonymised = 0, int $kept = 0, string $reason = 'legal_obligation'): array
    {
        return ['deleted' => $deleted, 'anonymised' => $anonymised, 'kept' => $kept, 'reason' => $kept > 0 ? $reason : null];
    }
}

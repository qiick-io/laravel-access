<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Qiick\Access\Laravel\MigrationSettings;

/**
 * PrincipalDirectory over the package principals table (design §15.4).
 *
 * Race safety: creation goes through createOrFirst(), so a concurrent insert
 * that wins the unique index (membership or instance singleton) is returned
 * instead of failing. Owner chains are walked at most eight principals deep
 * (the principal and its owners): a deeper or cyclic chain is refused on
 * creation and inactive at use time (P8).
 *
 * @api
 */
final class EloquentPrincipalDirectory implements PrincipalDirectory
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Dispatcher $events,
        private readonly ConfigRepository $config,
    ) {}

    public function forMember(Authenticatable $user, ?string $organisationId): Principal
    {
        $organisationId = $this->normalisedOrganisationId($organisationId);

        // The membership unique index makes this idempotent under concurrency.
        $principal = Principal::unguarded(fn (): Principal => $this->query()->createOrFirst(
            ['type' => 'user', 'user_id' => $user->getAuthIdentifier(), 'organisation_id' => $organisationId],
            ['name' => $this->displayName($user)],
        ));

        if ($principal->deactivation_reason === DeactivationReason::Erased) {
            throw new LogicException('The principal was erased and cannot be reactivated; a new principal needs a new decision (C17).');
        }

        // Only an offboarding is undone by a new membership: an admin, owner
        // or expiry deactivation outlives the next login (review N7). The
        // conditional update cannot undo an erasure committed meanwhile.
        $this->query()->whereKey($principal->id)
            ->where('deactivation_reason', DeactivationReason::Offboarded->value)
            ->update(['deactivated_at' => null, 'deactivation_reason' => null]);

        return $this->announceIfCreated($principal->refresh());
    }

    public function createService(
        string $type,
        ?string $organisationId,
        string $name,
        ?Principal $owner = null,
        ?Authenticatable $createdBy = null,
        array $metadata = [],
    ): Principal {
        if ($type === 'user') {
            throw new InvalidArgumentException('Membership principals are created with forMember().');
        }

        if (in_array($type, MigrationSettings::instanceTypes(), true)) {
            throw new InvalidArgumentException("Instance principals of type [{$type}] are created with instance().");
        }

        if (! in_array($type, $this->configuredTypes(), true)) {
            throw new InvalidArgumentException("Principal type [{$type}] is not listed in access.principals.types.");
        }

        $organisationId = $this->normalisedOrganisationId($organisationId);

        if ($owner !== null) {
            $this->assertUsableOwner($owner, $organisationId);
        }

        $principal = Principal::unguarded(fn (): Principal => $this->query()->create([
            'type' => $type,
            'organisation_id' => $organisationId,
            'name' => $name,
            'owner_principal_id' => $owner?->id,
            'created_by' => $createdBy?->getAuthIdentifier(),
            'metadata' => $metadata,
        ]));

        return $this->announceIfCreated($principal);
    }

    public function instance(string $type, string $name): Principal
    {
        // Instance types exist only with required organisations, so the
        // singleton index always backs this (review B5).
        if (! in_array($type, MigrationSettings::instanceTypes(), true)) {
            throw new InvalidArgumentException("Principal type [{$type}] is not listed in access.principals.instance_types.");
        }

        if ($this->query()->where('type', $type)->whereNull('organisation_id')->whereNotNull('user_id')->exists()) {
            throw new LogicException("An instance-level [{$type}] principal has a user; the organisation rule is missing from the schema (review B6).");
        }

        // Read first so repeated calls do not hit the unique index (logged
        // violations on PostgreSQL, review N17); the singleton index makes the
        // insert idempotent under concurrency, returning a winning insert.
        $principal = $this->query()->where('type', $type)->whereNull('organisation_id')->first()
            ?? Principal::unguarded(fn (): Principal => $this->query()->createOrFirst(['type' => $type], ['name' => $name]));

        return $this->announceIfCreated($principal);
    }

    public function deactivate(Principal $principal, DeactivationReason $reason): void
    {
        // On the principal model's connection, so the cascade is atomic where
        // the rows live, not on the default connection.
        $ownedIds = $this->database->connection($this->query()->getModel()->getConnectionName())->transaction(function () use ($principal, $reason): array {
            // An already deactivated principal keeps its reason and time, so an
            // erased tombstone is never rewritten (review B3).
            $this->query()->whereKey($principal->id)->whereNull('deactivated_at')
                ->update(['deactivated_at' => now(), 'deactivation_reason' => $reason->value]);

            return $this->deactivateOwned($principal->id);
        });

        $this->events->dispatch(new PrincipalDeactivated($principal->refresh(), $reason, $ownedIds));
    }

    public function eraseUser(Authenticatable $user, Closure $deleteUser): void
    {
        $principalModel = $this->query()->getModel();
        $connection = $this->database->connection($principalModel->getConnectionName());

        // The user delete waits on the principal rows this transaction locks:
        // on a second connection it would wait for itself (review R5).
        if ($this->database->connection((new (MigrationSettings::userModel()))->getConnectionName())->getName() !== $connection->getName()) {
            throw new LogicException('eraseUser() needs the users model and the principal model on one database connection.');
        }

        $erased = $connection->transaction(function () use ($connection, $user, $deleteUser): array {
            $erased = [];

            foreach ($this->query()->where('user_id', $user->getAuthIdentifier())->lockForUpdate()->get() as $principal) {
                $isErasedAlready = $principal->deactivation_reason === DeactivationReason::Erased;
                $principal->forceFill([
                    'user_id' => null,
                    'name' => 'Erased user',
                    'deactivated_at' => $isErasedAlready ? $principal->deactivated_at : now(),
                    'deactivation_reason' => DeactivationReason::Erased,
                ])->save();

                $erased[] = [$principal, $this->deactivateOwned($principal->id)];
            }

            $deleteUser($user);

            // A soft delete (or no delete) would leave the person's data in
            // place behind an "erased" audit trail, and a restore would let
            // forMember() mint a fresh principal past the tombstone (C17).
            // The raw query ignores soft-delete scopes.
            if ($connection->table(MigrationSettings::usersTable())->where((new (MigrationSettings::userModel()))->getKeyName(), $user->getAuthIdentifier())->exists()) {
                throw new LogicException('The user row still exists after the erasure callback; erasure needs a hard delete (forceDelete() on a soft-deleting model).');
            }

            return $erased;
        });

        foreach ($erased as [$principal, $ownedIds]) {
            $this->events->dispatch(new PrincipalDeactivated($principal, DeactivationReason::Erased, $ownedIds));
        }
    }

    public function isActive(string $principalId): bool
    {
        return $this->activeOwnerChain($principalId) !== null;
    }

    public function activeOwnerChain(string $principalId): ?array
    {
        $chain = [];
        $nextId = $principalId;

        // A cycle never reaches a principal without owner, so it runs out of steps.
        for ($step = 0; $nextId !== null && $step < $this->maxOwnerChain(); $step++) {
            $principal = $this->query()->whereKey($nextId)->first();

            if ($principal === null || ! $this->isUsable($principal)) {
                return null;
            }

            $chain[] = $principal;
            $nextId = $principal->owner_principal_id;
        }

        return $nextId === null ? $chain : null;
    }

    private function isUsable(Principal $principal): bool
    {
        return $principal->deactivated_at === null
            && ($principal->expires_at === null || $principal->expires_at->isFuture())
            && ($principal->type !== 'user' || $principal->user_id !== null)
            && $this->isAllowedAtItsLevel($principal);
    }

    /**
     * Fails closed on an instance-level row the organisation rule forbids,
     * even if the schema lost the rule (review B6).
     */
    private function isAllowedAtItsLevel(Principal $principal): bool
    {
        if ($principal->organisation_id !== null || ! MigrationSettings::isOrganisationRequired()) {
            return true;
        }

        return $principal->user_id === null && in_array($principal->type, MigrationSettings::instanceTypes(), true);
    }

    /**
     * Deactivates every active principal owned directly or transitively.
     *
     * @return list<string> every owned principal, active or not
     */
    private function deactivateOwned(string $principalId): array
    {
        $ownedIds = $this->ownedIds($principalId);

        $this->query()->whereIn('id', $ownedIds)->whereNull('deactivated_at')->update([
            'deactivated_at' => now(),
            'deactivation_reason' => DeactivationReason::OwnerDeactivated->value,
        ]);

        return $ownedIds;
    }

    /**
     * @throws InvalidArgumentException when the owner is in another organisation,
     *                                  inactive, or its chain is too deep or cyclic
     */
    private function assertUsableOwner(Principal $owner, ?string $organisationId): void
    {
        // Ownership never crosses tenants (API1 / BOLA, review B4). The stored
        // organisation counts, never the in-memory model (review N16).
        if ($this->query()->whereKey($owner->id)->value('organisation_id') !== $organisationId) {
            throw new InvalidArgumentException('A principal can only be owned by a principal of the same organisation.');
        }

        if (! $this->isOwnerChainShort($owner->id)) {
            throw new InvalidArgumentException('The owner chain is cyclic or deeper than eight principals.');
        }

        if (! $this->isActive($owner->id)) {
            throw new InvalidArgumentException('The owner principal is not active.');
        }
    }

    /**
     * UUIDs compare case-insensitively on PostgreSQL and MySQL but not on
     * SQLite, so one membership must never split by letter case (review N5).
     *
     * @throws InvalidArgumentException when organisations are required and none is given
     */
    private function normalisedOrganisationId(?string $organisationId): ?string
    {
        if ($organisationId === null && MigrationSettings::isOrganisationRequired()) {
            throw new InvalidArgumentException('An organisation is required for this principal (access.principals.organisation_required).');
        }

        return $organisationId === null ? null : strtolower($organisationId);
    }

    /**
     * The principal and its owners (design §15.7 P8).
     */
    private function maxOwnerChain(): int
    {
        return 8;
    }

    /**
     * Whether a new principal owned by $ownerId stays within the chain limit:
     * the owner's chain must end within one step less. A cycle never ends.
     */
    private function isOwnerChainShort(string $ownerId): bool
    {
        $nextId = $ownerId;

        for ($step = 1; $nextId !== null && $step < $this->maxOwnerChain(); $step++) {
            $nextId = $this->query()->whereKey($nextId)->value('owner_principal_id');
        }

        return $nextId === null;
    }

    /**
     * Every principal owned directly or transitively, breadth first, each once.
     *
     * @return list<string>
     */
    private function ownedIds(string $principalId): array
    {
        $ownedIds = [];
        $frontier = [$principalId];

        while ($frontier !== []) {
            $children = array_values(array_filter(
                $this->query()->whereIn('owner_principal_id', $frontier)->orderBy('created_at')->orderBy('id')->pluck('id')->all(),
                'is_string',
            ));
            $frontier = array_values(array_diff($children, $ownedIds, [$principalId]));
            $ownedIds = [...$ownedIds, ...$frontier];
        }

        return $ownedIds;
    }

    private function announceIfCreated(Principal $principal): Principal
    {
        if ($principal->wasRecentlyCreated) {
            $this->events->dispatch(new PrincipalCreated($principal));
        }

        return $principal;
    }

    /**
     * @return array<mixed>
     */
    private function configuredTypes(): array
    {
        $types = $this->config->get('access.principals.types', []);

        return is_array($types) ? $types : [];
    }

    private function displayName(Authenticatable $user): string
    {
        foreach (['name', 'email'] as $attribute) {
            $value = $user instanceof Model ? $user->getAttribute($attribute) : null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'User';
    }

    /**
     * @return Builder<Principal>
     */
    private function query(): Builder
    {
        $modelClass = $this->config->get('access.principals.model', Principal::class);

        if (! is_string($modelClass) || ! is_a($modelClass, Principal::class, true)) {
            throw new LogicException('access.principals.model must be Qiick\Access\Principals\Principal or a subclass.');
        }

        return $modelClass::query();
    }
}

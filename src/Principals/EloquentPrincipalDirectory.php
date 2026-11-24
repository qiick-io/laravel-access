<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

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
        $this->assertOrganisationAllowed($organisationId);

        // The membership unique index makes this idempotent under concurrency.
        $principal = $this->query()->createOrFirst(
            ['type' => 'user', 'user_id' => $user->getAuthIdentifier(), 'organisation_id' => $organisationId],
            ['name' => $this->displayName($user)],
        );

        if ($principal->deactivation_reason === DeactivationReason::Erased) {
            throw new LogicException('The principal was erased and cannot be reactivated; a new principal needs a new decision (C17).');
        }

        if ($principal->deactivated_at !== null) {
            $principal->forceFill(['deactivated_at' => null, 'deactivation_reason' => null])->save();
        }

        return $this->announceIfCreated($principal);
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

        $this->assertOrganisationAllowed($organisationId);

        if ($owner !== null && ! $this->isOwnerChainShort($owner->id)) {
            throw new InvalidArgumentException('The owner chain is cyclic or deeper than eight principals.');
        }

        $principal = $this->query()->create([
            'type' => $type,
            'organisation_id' => $organisationId,
            'name' => $name,
            'owner_principal_id' => $owner?->id,
            'created_by' => $createdBy?->getAuthIdentifier(),
            'metadata' => $metadata,
        ]);

        return $this->announceIfCreated($principal);
    }

    public function instance(string $type, string $name): Principal
    {
        if (! in_array($type, MigrationSettings::instanceTypes(), true)) {
            throw new InvalidArgumentException("Principal type [{$type}] is not listed in access.principals.instance_types.");
        }

        // The locked read keeps calls idempotent where no singleton index
        // exists (organisations not required); the index covers races elsewhere.
        $principal = $this->database->connection()->transaction(function () use ($type, $name): Principal {
            $identity = ['type' => $type, 'organisation_id' => null, 'user_id' => null];

            return $this->query()->where($identity)->lockForUpdate()->first()
                ?? $this->query()->createOrFirst($identity, ['name' => $name]);
        });

        return $this->announceIfCreated($principal);
    }

    public function deactivate(Principal $principal, DeactivationReason $reason): void
    {
        $ownedIds = $this->database->connection()->transaction(function () use ($principal, $reason): array {
            $now = now();
            $principal->forceFill(['deactivated_at' => $now, 'deactivation_reason' => $reason])->save();
            $ownedIds = $this->ownedIds($principal->id);

            $this->query()->whereIn('id', $ownedIds)->whereNull('deactivated_at')->update([
                'deactivated_at' => $now,
                'deactivation_reason' => DeactivationReason::OwnerDeactivated->value,
            ]);

            return $ownedIds;
        });

        $this->events->dispatch(new PrincipalDeactivated($principal, $reason, $ownedIds));
    }

    public function isActive(string $principalId): bool
    {
        $nextId = $principalId;

        // A cycle never reaches a principal without owner, so it runs out of steps.
        for ($step = 0; $nextId !== null && $step < $this->maxOwnerChain(); $step++) {
            $principal = $this->query()->whereKey($nextId)->first();

            if ($principal === null || ! $this->isUsable($principal)) {
                return false;
            }

            $nextId = $principal->owner_principal_id;
        }

        return $nextId === null;
    }

    private function isUsable(Principal $principal): bool
    {
        return $principal->deactivated_at === null
            && ($principal->expires_at === null || $principal->expires_at->isFuture())
            && ($principal->type !== 'user' || $principal->user_id !== null);
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
     * @throws InvalidArgumentException when organisations are required and none is given
     */
    private function assertOrganisationAllowed(?string $organisationId): void
    {
        if ($organisationId === null && MigrationSettings::isOrganisationRequired()) {
            throw new InvalidArgumentException('An organisation is required for this principal (access.principals.organisation_required).');
        }
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

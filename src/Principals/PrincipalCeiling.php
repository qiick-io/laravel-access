<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Laravel\MigrationSettings;

/**
 * The owner ceiling behind an API credential (design §15.4, decision
 * 2026-11-24): a key owned by a principal can never exceed what the person
 * behind it holds right now. Products call allows() from their key
 * authorizer, after the credential's own scopes.
 *
 * allows() is true only when:
 * - the principal and every owner up its chain are active
 *   (PrincipalDirectory::activeOwnerChain(): at most eight, no cycle);
 * - the nearest `user` principal of that chain (the principal itself for a
 *   membership principal) exists, every principal up to it in the
 *   principal's organisation;
 * - that user holds the permission in that organisation, read fresh and never
 *   through super-admin (EffectivePermissionResolver::hasAssignedPermission).
 *
 * A service or agent without a user owner, and any instance principal (a
 * declared instance type, refused by its type wherever it appears in the
 * chain, whatever its row says), has an empty ceiling: its powers come from
 * the product's scopes only. In a single-tenant product the organisation is NULL
 * all along the chain and the check runs with no team.
 *
 * The team context is switched to the principal's organisation for the check
 * and always restored, also when the check throws. Dependencies are resolved
 * per call, so a long-lived instance never pins a scoped resolver.
 *
 * @api
 */
final class PrincipalCeiling
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function allows(string $principalId, string $permission): bool
    {
        $owningUser = $this->owningUser($this->app->make(PrincipalDirectory::class)->activeOwnerChain($principalId) ?? []);

        if ($owningUser === null) {
            return false;
        }

        [$user, $organisationId] = $owningUser;
        $teams = $this->app->make(TeamContext::class);
        $previousTeamId = $teams->currentId();
        $teams->switchTo($organisationId);

        try {
            return $this->app->make(EffectivePermissionResolver::class)->hasAssignedPermission($user, $permission, true);
        } finally {
            $teams->switchTo($previousTeamId);
        }
    }

    /**
     * The user behind the nearest `user` principal of an active owner chain,
     * and the organisation every principal up to it shares; null when there is
     * none (an unowned service), the chain leaves the organisation, or it
     * reaches a declared instance type before a user.
     *
     * @param  list<Principal>  $chain  the principal, then its owners
     * @return array{Authenticatable, string|null}|null
     */
    private function owningUser(array $chain): ?array
    {
        $organisationId = ($chain[0] ?? null)?->organisation_id;
        $instanceTypes = MigrationSettings::instanceTypes();

        foreach ($chain as $principal) {
            // By type first: an instance principal has an empty ceiling even
            // when a missing organisation rule let its row gain an
            // organisation and an owner (review of PR #19).
            if (in_array($principal->type, $instanceTypes, true)) {
                return null;
            }

            if ($principal->organisation_id !== $organisationId) {
                return null;
            }

            if ($principal->type === 'user') {
                $user = MigrationSettings::userModel()::query()->whereKey($principal->user_id)->first();

                return $user instanceof Authenticatable ? [$user, $organisationId] : null;
            }
        }

        return null;
    }
}

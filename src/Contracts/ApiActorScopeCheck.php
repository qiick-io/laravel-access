<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * Whether an API actor's credential scopes cover a permission. Implemented by
 * the product or qiick/laravel-api-keys; the package holds no scope model.
 * An API actor passes a permission check only when the scopes allow it AND
 * it is within the owner ceiling
 * (EffectivePermissionResolver::isWithinOwnerCeiling), never through the
 * super-admin Gate bypass. Unbound, every API
 * actor is denied (fail-closed).
 *
 * @api
 */
interface ApiActorScopeCheck
{
    public function allows(ActorContext $actor, string $permission): bool;
}

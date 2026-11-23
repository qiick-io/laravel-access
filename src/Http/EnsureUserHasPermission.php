<?php

declare(strict_types=1);

namespace Qiick\Access\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\ApiActorScopeCheck;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\EffectivePermissionResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `permission:<PermissionEnum value>` (design §6). Products
 * register the alias themselves.
 *
 * A web actor needs the permission from the resolver (super-admin included).
 * An API actor needs both the credential's scopes (ApiActorScopeCheck) and the
 * owner's currently assigned permission, read fresh and without the
 * super-admin grant, so a key owned by a super-admin reaches only its scopes
 * ∩ what its owner really holds. With no ApiActorScopeCheck bound, API actors
 * are denied. Denials use the product's AuthorizationResponder body.
 *
 * Not a singleton: the resolver and actor resolver are scoped per request.
 *
 * @api
 */
final class EnsureUserHasPermission
{
    public function __construct(
        private readonly ActorResolver $actors,
        private readonly EffectivePermissionResolver $permissions,
        private readonly AuthorizationResponder $responder,
        private readonly Container $container,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        return $this->forbiddenResponse($request, $permission) ?? $next($request);
    }

    /**
     * The product's forbidden response when the request's actor lacks the
     * permission, null when it is granted. Shared with AuthorizesWithPermission.
     */
    public function forbiddenResponse(Request $request, string $permission): ?Response
    {
        if ($this->isGranted($request, $permission)) {
            return null;
        }

        return $this->responder->forbidden($request, $permission);
    }

    private function isGranted(Request $request, string $permission): bool
    {
        $actor = $this->actors->current();

        if (! $actor->isApiActor()) {
            $user = $request->user();

            return $user !== null && $this->permissions->hasPermission($user, $permission);
        }

        if ($actor->user === null || ! $this->container->bound(ApiActorScopeCheck::class)) {
            return false;
        }

        $scopeCheck = $this->container->make(ApiActorScopeCheck::class);

        return $scopeCheck->allows($actor, $permission)
            && $this->permissions->hasAssignedPermission($actor->user, $permission, true);
    }
}

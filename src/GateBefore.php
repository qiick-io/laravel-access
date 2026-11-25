<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\PermissionVocabulary;

/**
 * The package's single Gate::before callback (design §3.1). Never returns false;
 * denials come from definitions, Policies and middleware. API actors never reach
 * the super-admin or permission grants (steps 4–5). Dependencies are resolved
 * from the container on each invoke so a singleton GateBefore cannot pin a
 * scoped EffectivePermissionResolver / ActorResolver across requests (B1).
 *
 * @api
 */
final class GateBefore
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @param  array<mixed>  $arguments
     */
    public function __invoke(?Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        if ($user === null) {
            return null;
        }

        $actors = $this->app->make(ActorResolver::class);
        $context = $actors->current();

        $resolver = $this->app->make(EffectivePermissionResolver::class);

        // Design §3.3: bound context user must be the Gate user, else abstain.
        $boundUser = $context->user;

        if ($boundUser !== null && ! $resolver->isSamePrincipal($boundUser, $user)) {
            return null;
        }

        if ($context->isApiActor()) {
            return null;
        }

        $gate = $this->app->make(Gate::class);
        $policySubject = $arguments[0] ?? null;

        if ((is_object($policySubject) || is_string($policySubject)) && $gate->getPolicyFor($policySubject) !== null) {
            return null;
        }

        if ($resolver->isSuperAdmin($user)) {
            return true;
        }

        $vocabulary = $this->app->make(PermissionVocabulary::class);

        if ($arguments === [] && $vocabulary->isPermission($ability) && $resolver->hasPermission($user, $ability)) {
            return true;
        }

        return null;
    }
}

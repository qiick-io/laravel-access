<?php

declare(strict_types=1);

namespace Qiick\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\PermissionVocabulary;

/**
 * The package's single Gate::before callback (design §3.1). Never returns false;
 * denials come from definitions, Policies and middleware. API actors never reach
 * the super-admin or permission grants (steps 4–5).
 *
 * @api
 */
final class GateBefore
{
    public function __construct(
        private readonly ActorResolver $actors,
        private readonly EffectivePermissionResolver $resolver,
        private readonly PermissionVocabulary $vocabulary,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __invoke(?Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        if ($user === null) {
            return null;
        }

        if ($this->actors->current()->isApiActor()) {
            return null;
        }

        $policySubject = $arguments[0] ?? null;
        if ((is_object($policySubject) || is_string($policySubject)) && $this->gate->getPolicyFor($policySubject) !== null) {
            return null;
        }

        if ($this->resolver->isSuperAdmin($user)) {
            return true;
        }

        if ($arguments === [] && $this->vocabulary->isPermission($ability) && $this->resolver->hasPermission($user, $ability)) {
            return true;
        }

        return null;
    }
}

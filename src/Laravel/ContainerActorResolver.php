<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;

/**
 * Reads the bound AccessContext and the configured API-guard signal.
 * Never reads request input (design §3.3 / AC4).
 *
 * @api
 */
final class ContainerActorResolver implements ActorResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AuthFactory $auth,
    ) {}

    public function current(): ActorContext
    {
        $bound = AccessContext::current() ?? new ActorContext(ActorKind::Unattended, null);

        if ($bound->isApiActor()) {
            return $bound;
        }

        if ($this->defaultGuardIsApiGuard()) {
            // Guard signal without a bound API kind: fail closed as an API actor.
            return new ActorContext(ActorKind::ApiKey, $bound->user, $bound->credentialUuid);
        }

        return $bound;
    }

    private function defaultGuardIsApiGuard(): bool
    {
        $guards = $this->config->get('access.api_guards', []);
        if (! is_array($guards)) {
            return false;
        }

        $apiGuards = [];
        foreach ($guards as $guard) {
            if (is_string($guard) && $guard !== '') {
                $apiGuards[] = $guard;
            }
        }

        if ($apiGuards === []) {
            // in_array on [] is always false, so RemoveEarlyReturn is equivalent.
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $defaultGuard = $this->auth->getDefaultDriver();

        return in_array($defaultGuard, $apiGuards, true);
    }
}

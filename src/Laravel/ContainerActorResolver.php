<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;

/**
 * Reads the bound AccessContext and the default-guard signal. Never reads
 * request input (design §3.3 / AC4). On HTTP, any default guard that is not
 * listed in access.web_guards is an API guard, listed in access.api_guards or
 * not: the boot check sees only the guards configured at boot, and a guard
 * added later must fail closed rather than pass as a web session (no
 * super-admin, scopes ∩ owner). A guard in both lists is an API guard.
 * The guard signal is ignored
 * outside an active HTTP request so unattended jobs/commands cannot become
 * API actors via Auth::shouldUse(), unless access.trust_api_guard_in_console
 * is enabled in the testing environment (see config/access.php and
 * Qiick\Access\Testing\TrustApiGuardInConsole).
 *
 * @api
 */
final class ContainerActorResolver implements ActorResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AuthFactory $auth,
        private readonly Application $app,
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
        if ($this->app->runningInConsole() && ! $this->shouldTrustGuardInConsole()) {
            return false;
        }

        $defaultGuard = $this->auth->getDefaultDriver();

        return in_array($defaultGuard, $this->guardNames('access.api_guards'), true)
            || ! in_array($defaultGuard, $this->guardNames('access.web_guards'), true);
    }

    /**
     * @return list<string> the non-empty string entries of a guard-list config key
     */
    private function guardNames(string $configKey): array
    {
        $guards = $this->config->get($configKey, []);

        if (! is_array($guards)) {
            return [];
        }

        $guardNames = [];

        foreach ($guards as $guard) {
            if (is_string($guard) && $guard !== '') {
                $guardNames[] = $guard;
            }
        }

        return $guardNames;
    }

    /**
     * Product PHPUnit suites may set access.trust_api_guard_in_console so the
     * guard signal can be exercised while runningInConsole() is true. The flag
     * is ignored outside the testing environment (never a production lever).
     */
    private function shouldTrustGuardInConsole(): bool
    {
        if (! $this->app->environment('testing')) {
            return false;
        }

        return $this->config->get('access.trust_api_guard_in_console', false) === true;
    }
}

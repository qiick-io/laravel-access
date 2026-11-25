<?php

declare(strict_types=1);

namespace Qiick\Access\Http;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enrolment gate when two-factor authentication is required (design §5 / AC10).
 *
 * Order: no user → exempt route → API actor → not required → usable factor →
 * no method enabled → enrol. The exempt list is the only grace (R9).
 *
 * @api
 */
final class EnsureTwoFactorIsEnabled
{
    public function __construct(
        private readonly ActorResolver $actors,
        private readonly TwoFactorPolicy $policy,
        private readonly TwoFactorResponder $responder,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($this->actors->current()->isApiActor()) {
            return $next($request);
        }

        if (! $this->policy->isRequired($user)) {
            return $next($request);
        }

        if ($this->policy->hasUsableFactor($user)) {
            return $next($request);
        }

        if (! $this->policy->isAnyMethodEnabled()) {
            return $this->responder->unavailable();
        }

        return $this->responder->enrol($request);
    }

    private function isExempt(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $routeName = $route->getName();

        if (! is_string($routeName) || $routeName === '') {
            return false;
        }

        $patterns = $this->config->get('access.two_factor.exempt_route_names', []);

        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}

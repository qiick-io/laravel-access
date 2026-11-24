<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\LegacyGateBefore;
use Qiick\Access\GateBefore;
use Throwable;

/**
 * Builds the one Gate::before callback the provider registers (AC1), chosen
 * by the externa compatibility switches of design §9.2 step 6 and §9.4:
 *
 * - access.use_package_gate (default true): GateBefore decides; false hands
 *   the decision to the product's LegacyGateBefore;
 * - access.gate_shadow (default false): the other callback also runs, its
 *   answer is compared and every disagreement is logged with the ability,
 *   the actor kind, the credential UUID and the argument types, never user
 *   data or secrets. The shadowed callback can never change or break the
 *   decision: its exceptions are logged by class and swallowed.
 *
 * With the defaults the callback is GateBefore itself, with no wrapper.
 * Removed in v2 together with both switches (design §9.5).
 *
 * @internal
 */
final class GateBeforeSelector
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @return Closure(?Authenticatable, string, array<int, mixed>=): ?bool
     */
    public function callback(): Closure
    {
        $config = $this->app->make(ConfigRepository::class);
        $isPackageGate = $config->get('access.use_package_gate', true) === true;
        $packageGate = $this->app->make(GateBefore::class)(...);
        // Resolved per call, as GateBefore resolves its scoped dependencies.
        $legacyGate = fn (?Authenticatable $user, string $ability, array $arguments = []): ?bool => $this->app
            ->make(LegacyGateBefore::class)
            ->decide($user, $ability, array_values($arguments));

        if ($config->get('access.gate_shadow', false) !== true) {
            return $isPackageGate ? $packageGate : $legacyGate;
        }

        return function (?Authenticatable $user, string $ability, array $arguments = []) use ($isPackageGate, $packageGate, $legacyGate): ?bool {
            // A list for both callbacks and the log, whatever keys the caller used.
            $arguments = array_values($arguments);
            [$decidingGate, $shadowedGate] = $isPackageGate ? [$packageGate, $legacyGate] : [$legacyGate, $packageGate];
            $decision = $decidingGate($user, $ability, $arguments);

            try {
                $shadowDecision = $shadowedGate($user, $ability, $arguments);
            } catch (Throwable $exception) {
                // The class only: a message can carry user data or secrets.
                $this->logger()->warning('access.gate_shadow_failed', [
                    ...$this->logContext($ability, $arguments),
                    'exception' => $exception::class,
                ]);

                return $decision;
            }

            if ($shadowDecision !== $decision) {
                $this->logger()->warning('access.gate_shadow_mismatch', [
                    ...$this->logContext($ability, $arguments),
                    'package_decision' => $isPackageGate ? $decision : $shadowDecision,
                    'legacy_decision' => $isPackageGate ? $shadowDecision : $decision,
                ]);
            }

            return $decision;
        };
    }

    /**
     * What design §9.4 allows in a shadow log: no user ids, names, argument
     * values or credential secrets.
     *
     * @param  list<mixed>  $arguments
     * @return array{ability: string, actor_kind: string, credential_uuid: string|null, argument_types: list<string>}
     */
    private function logContext(string $ability, array $arguments): array
    {
        $actor = $this->app->make(ActorResolver::class)->current();

        return [
            'ability' => $ability,
            'actor_kind' => $actor->kind->value,
            'credential_uuid' => $actor->credentialUuid,
            'argument_types' => array_map(get_debug_type(...), $arguments),
        ];
    }

    private function logger(): LoggerInterface
    {
        $channel = $this->app->make(ConfigRepository::class)->get('access.gate_shadow_log_channel');
        $logs = $this->app->make(LogManager::class);

        return is_string($channel) && $channel !== '' ? $logs->channel($channel) : $logs;
    }
}

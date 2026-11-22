<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Closure;
use InvalidArgumentException;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use RuntimeException;

/**
 * Queue job middleware: re-binds the dispatcher's API actor kind and
 * credential UUID in the worker so Gate::before step 2 still abstains
 * (design §3.3 / AC5). Prefer the automatic payload stamp from
 * ActorPayloadStamper; use through() when a job must opt in explicitly.
 *
 * Jobs that carry an API actor should implement
 * Illuminate\Contracts\Queue\ShouldBeEncrypted when the queue connection is
 * not already encrypted end-to-end, so the credential UUID in the payload
 * is not stored in clear text.
 *
 * Always push/pop so a nested job restores the caller's actor even when the
 * payload stamp already matches (no early-return shortcut).
 *
 * @api
 */
final readonly class CarriesActorContext
{
    public function __construct(
        public ActorKind $kind,
        public ?string $credentialUuid = null,
    ) {
        if (! (new ActorContext($kind, null))->isApiActor()) {
            throw new InvalidArgumentException(
                'CarriesActorContext only accepts API actor kinds (ApiKey, OAuthGrant, PublicApi).',
            );
        }
    }

    public function handle(object $job, Closure $next): mixed
    {
        $store = app(AccessContext::class);
        $current = $store->get();

        $this->assertCredentialMatchesBound($current);

        $store->push(new ActorContext($this->kind, null, $this->credentialUuid));

        try {
            return $next($job);
        } finally {
            $store->pop();
        }
    }

    /**
     * When the worker already has the same API kind with a credential UUID
     * (product binding or payload stamp), refuse a mismatched middleware stamp.
     */
    private function assertCredentialMatchesBound(?ActorContext $current): void
    {
        if ($current === null || $current->kind !== $this->kind) {
            return;
        }

        if ($current->credentialUuid === null || $this->credentialUuid === null) {
            return;
        }

        if ($current->credentialUuid !== $this->credentialUuid) {
            throw new RuntimeException(
                'CarriesActorContext credential UUID does not match the actor already bound on the worker.',
            );
        }
    }

    /**
     * Build middleware from the currently bound context when it is an API actor.
     *
     * @return list<self>
     */
    public static function forCurrentApiActor(): array
    {
        $context = AccessContext::current();
        if ($context === null || ! $context->isApiActor()) {
            return [];
        }

        return [new self($context->kind, $context->credentialUuid)];
    }
}

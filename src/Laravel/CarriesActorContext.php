<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Closure;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;

/**
 * Queue job middleware: re-binds the dispatcher's actor kind and credential
 * UUID in the worker so Gate::before step 2 still abstains (design §3.3 / AC5).
 *
 * @api
 */
final class CarriesActorContext
{
    public function __construct(
        public ActorKind $kind,
        public ?string $credentialUuid = null,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        AccessContext::enter(new ActorContext($this->kind, null, $this->credentialUuid));

        try {
            return $next($job);
        } finally {
            AccessContext::clear();
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

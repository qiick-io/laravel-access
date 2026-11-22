<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Qiick\Access\Contracts\ActorContext;

/**
 * Request-scoped (and job-scoped) store for the verified actor.
 * Bound as a scoped container instance so Laravel flushes it per request,
 * per job and per test (design §3.3). Static enter/current/clear delegate
 * to that instance for the product call site.
 *
 * Nested job frames (sync dispatch inside a request, or CarriesActorContext)
 * push the previous actor and pop it when the frame ends so a job never
 * wipes the caller's actor.
 *
 * @api
 */
final class AccessContext
{
    private ?ActorContext $current = null;

    /**
     * Previous actors saved around nested job / middleware frames.
     *
     * @var list<?ActorContext>
     */
    private array $nestingStack = [];

    public function get(): ?ActorContext
    {
        return $this->current;
    }

    public function set(?ActorContext $context): void
    {
        $this->current = $context;
    }

    public function push(ActorContext $context): void
    {
        $this->nestingStack[] = $this->current;
        $this->current = $context;
    }

    public function pop(): void
    {
        if ($this->nestingStack === []) {
            $this->current = null;

            return;
        }

        $this->current = array_pop($this->nestingStack);
    }

    /**
     * Start a job frame. Async workers replace the store; sync jobs nest so
     * the surrounding request actor is restored by endJob().
     */
    public function beginJob(?ActorContext $context, bool $async): void
    {
        if ($async) {
            $this->nestingStack = [];
            $this->current = $context;

            return;
        }

        $this->nestingStack[] = $this->current;

        if ($context !== null) {
            $this->current = $context;
        }
    }

    /**
     * End a job frame started by beginJob().
     * Sync with no open frame is a no-op so clear()+enter() is not wiped.
     */
    public function endJob(bool $async): void
    {
        if ($async) {
            $this->nestingStack = [];
            $this->current = null;

            // Next branch returns because the stack was just cleared — equivalent.
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if ($this->nestingStack === []) {
            return;
        }

        $this->current = array_pop($this->nestingStack);
    }

    /** Products call this after a credential verifies (design §3.3). */
    public static function enter(ActorContext $context): void
    {
        self::store()->set($context);
    }

    public static function current(): ?ActorContext
    {
        return self::store()->get();
    }

    public static function clear(): void
    {
        $store = self::store();
        $store->current = null;
        $store->nestingStack = [];
    }

    private static function store(): self
    {
        return app(self::class);
    }
}

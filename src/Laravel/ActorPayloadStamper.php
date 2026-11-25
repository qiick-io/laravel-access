<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;

/**
 * Stamps the current API actor onto queued job payloads and rebinds it when
 * the worker starts the job (design §3.3 / AC5). Lives outside the service
 * provider so mutation testing can cover sync vs async framing.
 *
 * @api
 */
final class ActorPayloadStamper
{
    public const PAYLOAD_KEY = 'qiick_access';

    /**
     * Extra payload keys for Queue::createPayloadUsing (merged into the job).
     *
     * @return array<string, mixed>
     */
    public function stamp(): array
    {
        $context = AccessContext::current();
        if ($context === null || ! $context->isApiActor()) {
            return [];
        }

        return [
            self::PAYLOAD_KEY => [
                'kind' => $context->kind->value,
                'credential_uuid' => $context->credentialUuid,
            ],
        ];
    }

    /**
     * Rebind the stamped actor for a job that is about to run.
     *
     * @param  array<array-key, mixed>  $payload  Job payload from Queue\Jobs\Job::payload()
     */
    public function beginJob(array $payload, string $connectionName): void
    {
        app(AccessContext::class)->beginJob(
            $this->actorFromPayload($payload),
            $this->isAsyncConnection($connectionName),
        );
    }

    public function endJob(string $connectionName): void
    {
        app(AccessContext::class)->endJob($this->isAsyncConnection($connectionName));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function actorFromPayload(array $payload): ?ActorContext
    {
        $actor = $payload[self::PAYLOAD_KEY] ?? null;
        if (! is_array($actor)) {
            $actor = [];
        }

        $kindValue = $actor['kind'] ?? null;
        if (! is_string($kindValue)) {
            return null;
        }

        $kind = ActorKind::tryFrom($kindValue);
        if ($kind === null || ! (new ActorContext($kind, null))->isApiActor()) {
            return null;
        }

        $uuid = $actor['credential_uuid'] ?? null;

        return new ActorContext(
            $kind,
            null,
            is_string($uuid) ? $uuid : null,
        );
    }

    public function isAsyncConnection(string $connectionName): bool
    {
        return $connectionName !== 'sync';
    }
}

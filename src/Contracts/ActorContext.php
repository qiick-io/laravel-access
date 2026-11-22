<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Typed actor bound by the product authenticator after a credential verifies.
 * Never built from request input (design §3.3 / AC4).
 *
 * @api
 */
// @pest-arch-ignore-next-line — design §2.2: readonly DTO under Contracts/, not an interface
final readonly class ActorContext
{
    public function __construct(
        public ActorKind $kind,
        public ?Authenticatable $user,
        public ?string $credentialUuid = null,
    ) {}

    /**
     * ApiKey, OAuthGrant or PublicApi — never receives Gate::before steps 4–5.
     */
    public function isApiActor(): bool
    {
        return match ($this->kind) {
            ActorKind::ApiKey, ActorKind::OAuthGrant, ActorKind::PublicApi => true,
            ActorKind::WebSession, ActorKind::Unattended => false,
        };
    }
}

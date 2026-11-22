<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Minimal Authenticatable for unit tests — no Eloquent, no database.
 */
class FakeAuthenticatable implements Authenticatable
{
    public function __construct(
        private readonly string|int $id = 1,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string|int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

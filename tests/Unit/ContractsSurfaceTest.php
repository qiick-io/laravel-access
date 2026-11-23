<?php

declare(strict_types=1);

use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\ApiActorScopeCheck;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;

it('exposes every design §2.2 contract interface', function (string $interface): void {
    expect(interface_exists($interface))->toBeTrue();
})->with([
    ActorResolver::class,
    PermissionVocabulary::class,
    RoleCatalog::class,
    GroupRoleSource::class,
    TeamContext::class,
    TwoFactorPolicy::class,
    TwoFactorResponder::class,
    AuthorizationResponder::class,
    ActiveUsers::class,
    ApiActorScopeCheck::class,
]);

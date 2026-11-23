<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\SpatieTeamContext;
use Spatie\Permission\PermissionRegistrar;

it('reads and sets the Spatie permissions team id', function (): void {
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('getPermissionsTeamId')->once()->andReturn(7);
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with(9);
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('user')->andReturn(null);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('guard')->andReturn($guard);

    $context = new SpatieTeamContext($registrar, $auth);

    expect($context->currentId())->toBe(7);

    $context->switchTo(9);
});

it('unsets roles and permissions on the authenticated user and flushes the resolver', function (): void {
    $user = new class implements Authenticatable
    {
        /** @var list<string> */
        public array $unsetRelations = [];

        public function unsetRelation(string $relation): void
        {
            $this->unsetRelations[] = $relation;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
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
    };

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with('org-a');
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('user')->andReturn($user);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('guard')->andReturn($guard);

    $flushCount = 0;
    $context = new SpatieTeamContext(
        $registrar,
        $auth,
        static function () use (&$flushCount): void {
            $flushCount++;
        },
    );

    $context->switchTo('org-a');

    expect($flushCount)->toBe(1)
        ->and($user->unsetRelations)->toBe(['roles', 'permissions']);
});

it('unsets relations on the AccessContext actor even when the default guard has another user', function (): void {
    $boundUser = new class implements Authenticatable
    {
        /** @var list<string> */
        public array $unsetRelations = [];

        public function unsetRelation(string $relation): void
        {
            $this->unsetRelations[] = $relation;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 42;
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
    };

    $guardUser = new class implements Authenticatable
    {
        /** @var list<string> */
        public array $unsetRelations = [];

        public function unsetRelation(string $relation): void
        {
            $this->unsetRelations[] = $relation;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
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
    };

    $boundActor = new ActorContext(ActorKind::ApiKey, $boundUser, 'cred');
    AccessContext::enter($boundActor);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with('org-b');
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('user')->andReturn($guardUser);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('guard')->andReturn($guard);

    (new SpatieTeamContext($registrar, $auth))->switchTo('org-b');

    // The bound actor survives the switch: an API actor stays an API actor.
    expect($boundUser->unsetRelations)->toBe(['roles', 'permissions'])
        ->and($guardUser->unsetRelations)->toBe([])
        ->and(AccessContext::current())->toBe($boundActor);

    AccessContext::clear();
});

it('skips user work but still flushes the resolver when nobody is authenticated', function (): void {
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with(null);
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('user')->andReturn(null);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('guard')->andReturn($guard);

    $flushCount = 0;
    $context = new SpatieTeamContext(
        $registrar,
        $auth,
        static function () use (&$flushCount): void {
            $flushCount++;
        },
    );

    $context->switchTo(null);

    // Users other than the actor may hold warm entries for the old team (C29).
    expect($flushCount)->toBe(1);
});

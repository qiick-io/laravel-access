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

it('unsets roles and permissions on the authenticated user and runs the forget callback', function (): void {
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

    $forgotten = null;
    $context = new SpatieTeamContext(
        $registrar,
        $auth,
        static function (Authenticatable $actor) use (&$forgotten): void {
            $forgotten = $actor;
        },
    );

    $context->switchTo('org-a');

    expect($forgotten)->toBe($user)
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

    AccessContext::enter(new ActorContext(ActorKind::ApiKey, $boundUser, 'cred'));

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with('org-b');
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('user')->andReturn($guardUser);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('guard')->andReturn($guard);

    (new SpatieTeamContext($registrar, $auth))->switchTo('org-b');

    expect($boundUser->unsetRelations)->toBe(['roles', 'permissions'])
        ->and($guardUser->unsetRelations)->toBe([]);

    AccessContext::clear();
});

it('skips user work when nobody is authenticated', function (): void {
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->shouldReceive('setPermissionsTeamId')->once()->with(null);
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturn(true);

    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('user')->andReturn(null);
    $auth = Mockery::mock(AuthFactory::class);
    $auth->shouldReceive('guard')->andReturn($guard);

    $called = false;
    $context = new SpatieTeamContext(
        $registrar,
        $auth,
        static function () use (&$called): void {
            $called = true;
        },
    );

    $context->switchTo(null);

    expect($called)->toBeFalse();
});

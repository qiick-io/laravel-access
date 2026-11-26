<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ApiActorScopeCheck;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Http\EnsureUserHasPermission;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionGatedRequest;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/*
 * Design §6 route middleware and FormRequest trait. Web actors: the resolver,
 * super-admin included. API actors: credential scopes ∩ the owner ceiling
 * (assigned permissions, or the vocabulary for a super-admin owner; never the
 * Gate bypass), and denied when no scope check is bound.
 */

/**
 * Scope check granting a fixed permission list; records what it was asked.
 */
final class ListedScopeCheck implements ApiActorScopeCheck
{
    /** @var list<array{ActorContext, string}> */
    public array $calls = [];

    /**
     * @param  list<string>  $scopedPermissions
     */
    public function __construct(private readonly array $scopedPermissions) {}

    public function allows(ActorContext $actor, string $permission): bool
    {
        $this->calls[] = [$actor, $permission];

        return in_array($permission, $this->scopedPermissions, true);
    }
}

beforeEach(function (): void {
    config()->set('permission.teams', false);
    config()->set('auth.providers.users.model', SpatieUser::class);

    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    // Package-root vendor path: Testbench base_path() points at its sandbox app.
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    Permission::create(['name' => FakePermissionEnum::CanEditUsers->value, 'guard_name' => 'web']);
    Role::create(['name' => 'super-admin', 'guard_name' => 'web']);

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetInstance(EffectivePermissionResolver::class);

    $this->app->instance(AuthorizationResponder::class, new class implements AuthorizationResponder
    {
        public function forbidden(Request $request, string $permission): Response
        {
            return new Response('forbidden:'.$permission, 403);
        }
    });
});

afterEach(function (): void {
    AccessContext::clear();

    foreach (['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function requestAs(?SpatieUser $user): Request
{
    $request = Request::create('/gated');
    $request->setUserResolver(static fn (): ?SpatieUser => $user);

    return $request;
}

function passThroughPermissionMiddleware(Request $request, string ...$permissions): Response
{
    return app(EnsureUserHasPermission::class)->handle(
        $request,
        static fn (): Response => new Response('next'),
        ...$permissions,
    );
}

function userWithPermissions(string ...$permissionNames): SpatieUser
{
    $user = SpatieUser::query()->create(['email' => uniqid('user', true).'@example.com']);
    $user->givePermissionTo($permissionNames);

    return $user;
}

function enterApiActor(?SpatieUser $owner): ActorContext
{
    $actor = new ActorContext(ActorKind::ApiKey, $owner, 'key-uuid');
    AccessContext::enter($actor);

    return $actor;
}

it('denies a guest with the product forbidden response', function (): void {
    $response = passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toBe('forbidden:can-show-users');
});

it('lets a web user through only with the permission', function (): void {
    $user = userWithPermissions(FakePermissionEnum::CanShowUsers->value);

    expect(passThroughPermissionMiddleware(requestAs($user), FakePermissionEnum::CanShowUsers->value)->getContent())->toBe('next')
        ->and(passThroughPermissionMiddleware(requestAs($user), FakePermissionEnum::CanEditUsers->value)->getContent())->toBe('forbidden:can-edit-users');
});

it('lets a web super-admin through without an assigned permission', function (): void {
    $user = userWithPermissions();
    $user->assignRole('super-admin');

    expect(passThroughPermissionMiddleware(requestAs($user), FakePermissionEnum::CanEditUsers->value)->getContent())->toBe('next');
});

it('limits an API actor whose owner is super-admin to its scopes', function (): void {
    $owner = userWithPermissions(FakePermissionEnum::CanShowUsers->value, FakePermissionEnum::CanEditUsers->value);
    $owner->assignRole('super-admin');
    $actor = enterApiActor($owner);
    $scopeCheck = new ListedScopeCheck([FakePermissionEnum::CanShowUsers->value]);
    $this->app->instance(ApiActorScopeCheck::class, $scopeCheck);

    // The key request carries no Gate user: the owner comes from the actor.
    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())->toBe('next')
        ->and(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanEditUsers->value)->getContent())->toBe('forbidden:can-edit-users')
        ->and($scopeCheck->calls[0])->toBe([$actor, FakePermissionEnum::CanShowUsers->value]);
});

it('gives an API actor of a super-admin owner the permission vocabulary within its scopes', function (): void {
    // Decision 2026-11-26: a super-admin's key ceiling is every explicit
    // permission, so a super-admin alone can create a working key.
    $owner = userWithPermissions();
    $owner->assignRole('super-admin');
    enterApiActor($owner);
    $this->app->instance(ApiActorScopeCheck::class, new ListedScopeCheck([FakePermissionEnum::CanShowUsers->value]));

    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())->toBe('next')
        ->and(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanEditUsers->value)->getContent())->toBe('forbidden:can-edit-users');
});

it('never gives a super-admin owner\'s API actor a permission outside the vocabulary', function (): void {
    Permission::create(['name' => 'can-purge-everything', 'guard_name' => 'web']);
    Role::findByName('super-admin')->givePermissionTo('can-purge-everything');
    $owner = userWithPermissions();
    $owner->assignRole('super-admin');
    enterApiActor($owner);
    $this->app->instance(ApiActorScopeCheck::class, new ListedScopeCheck(['can-purge-everything']));

    expect(passThroughPermissionMiddleware(requestAs(null), 'can-purge-everything')->getContent())
        ->toBe('forbidden:can-purge-everything');
});

it('denies an API actor as soon as its owner stops being super-admin', function (): void {
    $owner = userWithPermissions();
    $owner->assignRole('super-admin');
    enterApiActor($owner);
    $this->app->instance(ApiActorScopeCheck::class, new ListedScopeCheck([FakePermissionEnum::CanShowUsers->value]));

    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())->toBe('next');

    // A demotion that fires no Spatie event: only the fresh read catches it.
    DB::table('model_has_roles')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users');
});

it('denies an API actor as soon as its owner loses the permission', function (): void {
    $owner = userWithPermissions(FakePermissionEnum::CanShowUsers->value);
    enterApiActor($owner);
    $this->app->instance(ApiActorScopeCheck::class, new ListedScopeCheck([FakePermissionEnum::CanShowUsers->value]));

    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())->toBe('next');

    // A revocation that fires no Spatie event: only the fresh read catches it.
    DB::table('model_has_permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users');
});

it('denies an API actor when no scope check is bound', function (): void {
    $owner = userWithPermissions(FakePermissionEnum::CanShowUsers->value);
    enterApiActor($owner);

    expect(passThroughPermissionMiddleware(requestAs($owner), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users');
});

it('denies an API actor without an owner', function (): void {
    AccessContext::enter(new ActorContext(ActorKind::PublicApi, null));
    $this->app->instance(ApiActorScopeCheck::class, new ListedScopeCheck([FakePermissionEnum::CanShowUsers->value]));

    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users');
});

it('authorizes a FormRequest with the same decision and body', function (): void {
    $editor = userWithPermissions(FakePermissionEnum::CanEditUsers->value);
    $viewer = userWithPermissions(FakePermissionEnum::CanShowUsers->value);

    $grantedRequest = PermissionGatedRequest::create('/gated');
    $grantedRequest->setUserResolver(static fn (): SpatieUser => $editor);

    $deniedRequest = PermissionGatedRequest::create('/gated');
    $deniedRequest->setUserResolver(static fn (): SpatieUser => $viewer);

    expect($grantedRequest->authorize())->toBeTrue();

    try {
        $deniedRequest->authorize();
        $this->fail('authorize() should throw for a missing permission.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(403)
            ->and($exception->getResponse()->getContent())->toBe('forbidden:can-edit-users');
    }
});

it('requires every permission listed on the route', function (): void {
    $user = userWithPermissions(FakePermissionEnum::CanShowUsers->value);
    $bothUser = userWithPermissions(FakePermissionEnum::CanShowUsers->value, FakePermissionEnum::CanEditUsers->value);

    expect(passThroughPermissionMiddleware(requestAs($user), FakePermissionEnum::CanShowUsers->value, FakePermissionEnum::CanEditUsers->value)->getContent())
        ->toBe('forbidden:can-edit-users')
        ->and(passThroughPermissionMiddleware(requestAs($bothUser), FakePermissionEnum::CanShowUsers->value, FakePermissionEnum::CanEditUsers->value)->getContent())
        ->toBe('next');
});

it('refuses a route that names no permission', function (): void {
    expect(fn () => passThroughPermissionMiddleware(requestAs(userWithPermissions())))
        ->toThrow(InvalidArgumentException::class, 'permission:');
});

it('denies a user authenticated by a guard that is neither web nor API', function (): void {
    $owner = userWithPermissions(FakePermissionEnum::CanShowUsers->value);
    $owner->assignRole('super-admin');

    // A token guard the product forgot to list in access.api_guards.
    config()->set('auth.guards.token', ['driver' => 'session', 'provider' => 'users']);
    auth()->shouldUse('token');

    expect(passThroughPermissionMiddleware(requestAs($owner), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users');

    config()->set('access.web_guards', ['web', 'token']);

    expect(passThroughPermissionMiddleware(requestAs($owner), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('next');
});

it('denies a web request whose user is not the bound actor', function (): void {
    $boundUser = userWithPermissions();
    $requestUser = userWithPermissions(FakePermissionEnum::CanShowUsers->value);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $boundUser));

    expect(passThroughPermissionMiddleware(requestAs($requestUser), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users')
        ->and(passThroughPermissionMiddleware(requestAs($boundUser), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('forbidden:can-show-users');

    $boundUser->givePermissionTo(FakePermissionEnum::CanShowUsers->value);

    expect(passThroughPermissionMiddleware(requestAs($boundUser), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('next');
});

it('never counts the super-admin role\'s own permissions beyond the vocabulary for an API actor', function (): void {
    Permission::create(['name' => 'can-delete-users', 'guard_name' => 'web']);
    Permission::create(['name' => 'can-purge-everything', 'guard_name' => 'web']);
    $superAdminRole = Role::findByName('super-admin');
    $superAdminRole->givePermissionTo('can-purge-everything');
    $editorRole = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $editorRole->givePermissionTo(FakePermissionEnum::CanShowUsers->value);

    $owner = userWithPermissions('can-delete-users');
    $owner->assignRole($superAdminRole, $editorRole);
    enterApiActor($owner);
    $this->app->instance(ApiActorScopeCheck::class, new ListedScopeCheck([
        FakePermissionEnum::CanShowUsers->value,
        FakePermissionEnum::CanEditUsers->value,
        'can-delete-users',
        'can-purge-everything',
    ]));

    // The vocabulary exactly: nothing outside it, whether assigned directly,
    // through an ordinary role or on the super-admin role row.
    expect(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanEditUsers->value)->getContent())
        ->toBe('next')
        ->and(passThroughPermissionMiddleware(requestAs(null), FakePermissionEnum::CanShowUsers->value)->getContent())
        ->toBe('next')
        ->and(passThroughPermissionMiddleware(requestAs(null), 'can-delete-users')->getContent())
        ->toBe('forbidden:can-delete-users')
        ->and(passThroughPermissionMiddleware(requestAs(null), 'can-purge-everything')->getContent())
        ->toBe('forbidden:can-purge-everything');
});

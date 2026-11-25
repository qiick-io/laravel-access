<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\Laravel\EloquentGroupRoleSource;
use Qiick\Access\Laravel\NoTeamContext;
use Qiick\Access\Tests\Support\FakeAuthenticatable;
use Qiick\Access\Tests\Support\SpatieUser;

beforeEach(function (): void {
    // user_group_user.user_id points at this model's table only.
    config()->set('auth.providers.users.model', FakeAuthenticatable::class);

    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id');
        $table->string('name');
        $table->json('metadata')->nullable();
        $table->uuid('organisation_id')->nullable();
        $table->boolean('is_instance')->default(false);
        $table->softDeletes();
    });
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('user_group_id');
        $table->dateTime('expires_at')->nullable();
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_group_id');
        $table->unsignedBigInteger('role_id');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
});

it('binds EloquentGroupRoleSource as the default GroupRoleSource', function (): void {
    expect(app(GroupRoleSource::class))->toBeInstanceOf(EloquentGroupRoleSource::class);
});

it('returns role ids from active groups the user belongs to', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        'id' => 1,
        'public_id' => '11111111-1111-7111-8111-111111111111',
        'name' => 'ops',
        'organisation_id' => null,
        'is_instance' => false,
        'deleted_at' => null,
    ]);
    $connection->table('user_group_user')->insert([
        'user_id' => 10,
        'user_group_id' => 1,
        'expires_at' => null,
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 3],
        ['user_group_id' => 1, 'role_id' => 2],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([2, 3]);
})->group('engines');

it('excludes soft-deleted groups so they grant nothing (C7)', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        [
            'id' => 1,
            'public_id' => '11111111-1111-7111-8111-111111111111',
            'name' => 'live',
            'organisation_id' => null,
            'is_instance' => false,
            'deleted_at' => null,
        ],
        [
            'id' => 2,
            'public_id' => '22222222-2222-7222-8222-222222222222',
            'name' => 'trashed',
            'organisation_id' => null,
            'is_instance' => false,
            'deleted_at' => now(),
        ],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 10, 'user_group_id' => 1, 'expires_at' => null],
        ['user_id' => 10, 'user_group_id' => 2, 'expires_at' => null],
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 5],
        ['user_group_id' => 2, 'role_id' => 99],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([5])
        ->and($roleIds)->not->toContain(99);
})->group('engines');

it('ignores memberships whose expires_at is in the past (A2 / AC15)', function (): void {
    $this->travelTo(now()->parse('2026-11-22 12:00:00'));

    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        [
            'id' => 1,
            'public_id' => '11111111-1111-7111-8111-111111111111',
            'name' => 'current',
            'organisation_id' => null,
            'is_instance' => false,
            'deleted_at' => null,
        ],
        [
            'id' => 2,
            'public_id' => '22222222-2222-7222-8222-222222222222',
            'name' => 'expired',
            'organisation_id' => null,
            'is_instance' => false,
            'deleted_at' => null,
        ],
        [
            'id' => 3,
            'public_id' => '33333333-3333-7333-8333-333333333333',
            'name' => 'future',
            'organisation_id' => null,
            'is_instance' => false,
            'deleted_at' => null,
        ],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 10, 'user_group_id' => 1, 'expires_at' => null],
        ['user_id' => 10, 'user_group_id' => 2, 'expires_at' => '2026-11-22 11:59:59'],
        ['user_id' => 10, 'user_group_id' => 3, 'expires_at' => '2026-11-22 12:00:01'],
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 1],
        ['user_group_id' => 2, 'role_id' => 2],
        ['user_group_id' => 3, 'role_id' => 3],
    ]);

    $roleIds = app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(10));

    expect($roleIds)->toBe([1, 3])
        ->and($roleIds)->not->toContain(2);
})->group('engines');

it('returns an empty list when the user has no group memberships', function (): void {
    expect(app(GroupRoleSource::class)->roleIdsFor(new FakeAuthenticatable(1)))->toBe([]);
})->group('engines');

it('scopes organisation groups to the current team and always includes instance groups', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        [
            'id' => 1,
            'public_id' => '11111111-1111-7111-8111-111111111111',
            'name' => 'instance-admins',
            'organisation_id' => null,
            'is_instance' => true,
            'deleted_at' => null,
        ],
        [
            'id' => 2,
            'public_id' => '22222222-2222-7222-8222-222222222222',
            'name' => 'org-a',
            'organisation_id' => 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            'is_instance' => false,
            'deleted_at' => null,
        ],
        [
            'id' => 3,
            'public_id' => '33333333-3333-7333-8333-333333333333',
            'name' => 'org-b',
            'organisation_id' => 'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
            'is_instance' => false,
            'deleted_at' => null,
        ],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 10, 'user_group_id' => 1, 'expires_at' => null],
        ['user_id' => 10, 'user_group_id' => 2, 'expires_at' => null],
        ['user_id' => 10, 'user_group_id' => 3, 'expires_at' => null],
    ]);
    $connection->table('user_group_role')->insert([
        ['user_group_id' => 1, 'role_id' => 1],
        ['user_group_id' => 2, 'role_id' => 2],
        ['user_group_id' => 3, 'role_id' => 3],
    ]);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa');

    $source = new EloquentGroupRoleSource(app('db'), $teamContext, app('config'));
    $user = new FakeAuthenticatable(10);

    expect($source->roleIdsFor($user))->toBe([1, 2])
        ->and($source->instanceRoleIdsFor($user))->toBe([1])
        ->and($source->instanceRoleIdsFor($user))->not->toContain(2);
})->group('engines');

it('does not treat organisation-group roles as instance super-admin sources', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        'id' => 1,
        'public_id' => '11111111-1111-7111-8111-111111111111',
        'name' => 'org-sa',
        'organisation_id' => 'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
        'is_instance' => false,
        'deleted_at' => null,
    ]);
    $connection->table('user_group_user')->insert([
        'user_id' => 10,
        'user_group_id' => 1,
        'expires_at' => null,
    ]);
    $connection->table('user_group_role')->insert([
        'user_group_id' => 1,
        'role_id' => 99,
    ]);

    $source = new EloquentGroupRoleSource(app('db'), new NoTeamContext, app('config'));

    expect($source->roleIdsFor(new FakeAuthenticatable(10)))->toBe([])
        ->and($source->instanceRoleIdsFor(new FakeAuthenticatable(10)))->toBe([]);
})->group('engines');

it('gives group roles only to the member model, not to another principal with the same id', function (): void {
    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        'id' => 1,
        'public_id' => '11111111-1111-7111-8111-111111111111',
        'name' => 'instance-admins',
        'organisation_id' => null,
        'is_instance' => true,
        'deleted_at' => null,
    ]);
    $connection->table('user_group_user')->insert(['user_id' => 10, 'user_group_id' => 1, 'expires_at' => null]);
    $connection->table('user_group_role')->insert(['user_group_id' => 1, 'role_id' => 1]);

    $otherGuardPrincipal = new class(10) implements Authenticatable
    {
        public function __construct(private readonly int $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
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
    };
    $source = app(GroupRoleSource::class);

    expect($source->instanceRoleIdsFor(new FakeAuthenticatable(10)))->toBe([1])
        ->and($source->instanceRoleIdsFor($otherGuardPrincipal))->toBe([])
        ->and($source->roleIdsFor($otherGuardPrincipal))->toBe([]);

    config()->set('access.group_member_model', $otherGuardPrincipal::class);

    expect($source->instanceRoleIdsFor($otherGuardPrincipal))->toBe([1])
        ->and($source->instanceRoleIdsFor(new FakeAuthenticatable(10)))->toBe([]);

    config()->set('access.group_member_model', null);
    config()->set('auth.providers.users.model', null);

    expect($source->instanceRoleIdsFor(new FakeAuthenticatable(10)))->toBe([]);
})->group('engines');

/**
 * A subclass of the member model on its own table: another principal (review B1).
 */
final class OwnTableAdmin extends SpatieUser
{
    protected $table = 'admins';
}

it('gives no group roles to a subclass of the member model that has its own table', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);

    $connection = app('db')->connection();
    $connection->table('user_groups')->insert([
        'id' => 1,
        'public_id' => '11111111-1111-7111-8111-111111111111',
        'name' => 'instance-admins',
        'organisation_id' => null,
        'is_instance' => true,
        'deleted_at' => null,
    ]);
    $connection->table('user_group_user')->insert(['user_id' => 1, 'user_group_id' => 1, 'expires_at' => null]);
    $connection->table('user_group_role')->insert(['user_group_id' => 1, 'role_id' => 1]);

    // Another connection name, even to the same database, is another principal.
    config()->set('database.connections.replica', config('database.connections.'.config('database.default')));
    $user = (new SpatieUser)->forceFill(['id' => 1]);
    $sameTableSubclass = (new class extends SpatieUser {})->forceFill(['id' => 1]);
    $ownTableAdmin = (new OwnTableAdmin)->forceFill(['id' => 1]);
    $otherConnectionUser = (new class extends SpatieUser
    {
        protected $connection = 'replica';
    })->forceFill(['id' => 1]);
    $source = app(GroupRoleSource::class);

    expect($source->instanceRoleIdsFor($user))->toBe([1])
        ->and($source->instanceRoleIdsFor($sameTableSubclass))->toBe([1])
        ->and($source->instanceRoleIdsFor($ownTableAdmin))->toBe([])
        ->and($source->roleIdsFor($ownTableAdmin))->toBe([])
        ->and($source->instanceRoleIdsFor($otherConnectionUser))->toBe([])
        ->and($source->instanceRoleIdsFor(new FakeAuthenticatable(1)))->toBe([]);
})->group('engines');

it('gives group roles to a user loaded from the database (beta.1 regression)', function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    $connection = app('db')->connection();
    $connection->table('users')->insert(['id' => 1, 'email' => 'member@example.com']);
    $connection->table('user_groups')->insert([
        ['id' => 1, 'public_id' => '11111111-1111-7111-8111-111111111111', 'name' => 'instance-admins', 'organisation_id' => null, 'is_instance' => true, 'deleted_at' => null],
        ['id' => 2, 'public_id' => '22222222-2222-7222-8222-222222222222', 'name' => 'editors', 'organisation_id' => null, 'is_instance' => false, 'deleted_at' => null],
    ]);
    $connection->table('user_group_user')->insert([
        ['user_id' => 1, 'user_group_id' => 1, 'expires_at' => null],
        ['user_id' => 1, 'user_group_id' => 2, 'expires_at' => null],
    ]);
    $connection->table('user_group_role')->insert([['user_group_id' => 1, 'role_id' => 1], ['user_group_id' => 2, 'role_id' => 2]]);

    // Loaded models carry their connection name; a new instance of the member
    // model has none. Both mean the same connection.
    $loadedUser = SpatieUser::query()->findOrFail(1);
    $source = app(GroupRoleSource::class);

    expect($loadedUser->getConnectionName())->not->toBeNull()
        ->and($source->instanceRoleIdsFor($loadedUser))->toBe([1])
        ->and($source->roleIdsFor($loadedUser))->toBe([1, 2]);

    Schema::drop('users');
})->group('engines');

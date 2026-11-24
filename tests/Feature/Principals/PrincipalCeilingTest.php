<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Laravel\NoTeamContext;
use Qiick\Access\Laravel\SpatieTeamContext;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\Principal;
use Qiick\Access\Principals\PrincipalCeiling;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * The owner ceiling behind an API key (design §15.4): the principal is active,
 * its nearest `user` principal up the owner chain, in the same organisation,
 * actually holds the permission in that organisation (read fresh, never through
 * super-admin). Services without a user owner and instance principals have an
 * empty ceiling: their powers come from the product's scopes only.
 */

pest()->group('engines');

beforeEach(function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('auth.providers.users.model', SpatieUser::class);
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service']);

    foreach (['principals', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    uuidTeamPermissionTables()->up();
    (require dirname(__DIR__, 3).'/database/migrations/principals/2026_11_24_100000_create_principals_table.php')->up();

    (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);
    $registrar = app(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->forgetCachedPermissions();

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->singleton(TeamContext::class, SpatieTeamContext::class);
    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $this->app->forgetInstance(PrincipalDirectory::class);

    Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    Permission::create(['name' => FakePermissionEnum::CanEditUsers->value, 'guard_name' => 'web']);
    Role::create(['name' => 'super-admin', 'guard_name' => 'web', 'organisation_id' => null]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    foreach (['principals', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
});

/**
 * A user holding can-show-users through an organisation role in $organisationId.
 */
function editorIn(string $organisationId, string $email = 'editor@example.com'): SpatieUser
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($organisationId);

    $role = Role::query()->where('name', 'viewer')->where('organisation_id', $organisationId)->first()
        ?? Role::create(['name' => 'viewer', 'guard_name' => 'web', 'organisation_id' => $organisationId]);
    $role->givePermissionTo(FakePermissionEnum::CanShowUsers->value);
    $user = SpatieUser::query()->create(['email' => $email]);
    $user->assignRole($role);

    $registrar->setPermissionsTeamId($previousTeamId);

    return $user;
}

/**
 * Spatie's tables with uuid team columns, as the fleet schema requires (A3):
 * the stock stub declares bigint team ids, which real engines refuse for uuids.
 */
function uuidTeamPermissionTables(): Migration
{
    $stub = (string) file_get_contents(dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub');
    $path = sys_get_temp_dir().'/qiick-access-uuid-team-permission-tables-'.md5($stub).'.php';
    file_put_contents($path, str_replace("\$table->unsignedBigInteger(\$columnNames['team_foreign_key'])", "\$table->uuid(\$columnNames['team_foreign_key'])", $stub));

    return require $path;
}

function ceiling(): PrincipalCeiling
{
    return app(PrincipalCeiling::class);
}

function principals(): PrincipalDirectory
{
    return app(PrincipalDirectory::class);
}

it('allows what the user behind a membership principal holds in that organisation', function (): void {
    $organisationA = (string) Str::uuid();
    $organisationB = (string) Str::uuid();
    $editor = editorIn($organisationA);
    $inA = principals()->forMember($editor, $organisationA);
    $inB = principals()->forMember($editor, $organisationB);

    expect(ceiling()->allows($inA->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(ceiling()->allows($inA->id, FakePermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and(ceiling()->allows($inB->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse()
        ->and(ceiling()->allows((string) Str::uuid7(), FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('bounds an agent by the nearest user principal that owns it', function (): void {
    $organisationId = (string) Str::uuid();
    $owner = principals()->forMember(editorIn($organisationId), $organisationId);
    $agent = principals()->createService('agent', $organisationId, 'Agent', $owner);
    $subAgent = principals()->createService('agent', $organisationId, 'Sub-agent', $agent);
    $unownedService = principals()->createService('service', $organisationId, 'Importer');

    expect(ceiling()->allows($agent->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(ceiling()->allows($subAgent->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(ceiling()->allows($subAgent->id, FakePermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and(ceiling()->allows($unownedService->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('gives nothing once any principal up the chain is inactive', function (string $change): void {
    $organisationId = (string) Str::uuid();
    $owner = principals()->forMember(editorIn($organisationId), $organisationId);
    $agent = principals()->createService('agent', $organisationId, 'Agent', $owner);

    match ($change) {
        'owner deactivated' => principals()->deactivate($owner, DeactivationReason::Admin),
        'owner expired' => DB::table('principals')->where('id', $owner->id)->update(['expires_at' => now()->subMinute()]),
        'agent expired' => DB::table('principals')->where('id', $agent->id)->update(['expires_at' => now()->subMinute()]),
    };

    expect(ceiling()->allows($agent->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
})->with(['owner deactivated', 'owner expired', 'agent expired']);

it('fails closed on an owner chain that crosses organisations or loops', function (): void {
    $organisationA = (string) Str::uuid();
    $organisationB = (string) Str::uuid();
    $editor = editorIn($organisationA);
    // The owner also holds the permission in B: only the organisation check refuses.
    app(PermissionRegistrar::class)->setPermissionsTeamId($organisationB);
    $editor->givePermissionTo(FakePermissionEnum::CanShowUsers->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $ownerInA = principals()->forMember($editor, $organisationA);
    $agentInB = principals()->createService('agent', $organisationB, 'Agent');
    // Written past the directory, which refuses both.
    DB::table('principals')->where('id', $agentInB->id)->update(['owner_principal_id' => $ownerInA->id]);
    $first = principals()->createService('agent', $organisationA, 'First');
    $second = principals()->createService('agent', $organisationA, 'Second', $first);
    DB::table('principals')->where('id', $first->id)->update(['owner_principal_id' => $second->id]);

    expect(ceiling()->allows($agentInB->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse()
        ->and(ceiling()->allows($second->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('never grants through the owner\'s super-admin role', function (): void {
    $organisationId = (string) Str::uuid();
    $superAdmin = SpatieUser::query()->create(['email' => 'admin@example.com']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($organisationId);
    $superAdmin->assignRole('super-admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $principal = principals()->forMember($superAdmin, $organisationId);

    expect(app(EffectivePermissionResolver::class)->isSuperAdmin($superAdmin))->toBeTrue()
        ->and(ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('works without organisations, as in a single-tenant product', function (): void {
    // Teams off and no organisations: rebuild the Spatie and principals tables.
    foreach (['principals', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles'] as $table) {
        Schema::dropIfExists($table);
    }
    config()->set('permission.teams', false);
    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
    (require dirname(__DIR__, 3).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
    (require dirname(__DIR__, 3).'/database/migrations/principals/2026_11_24_100000_create_principals_table.php')->up();
    app(PermissionRegistrar::class)->teams = false;
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->app->singleton(TeamContext::class, NoTeamContext::class);
    Permission::create(['name' => FakePermissionEnum::CanShowUsers->value, 'guard_name' => 'web']);
    $this->app->forgetInstance(PrincipalDirectory::class);
    $this->app->forgetInstance(EffectivePermissionResolver::class);
    $user = SpatieUser::query()->create(['email' => 'single@example.com']);
    $user->givePermissionTo(FakePermissionEnum::CanShowUsers->value);
    $member = principals()->forMember($user, null);
    $agent = principals()->createService('agent', null, 'Agent', $member);

    expect(ceiling()->allows($member->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(ceiling()->allows($agent->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(ceiling()->allows($agent->id, FakePermissionEnum::CanEditUsers->value))->toBeFalse();

    // Revoked with no Spatie event and no team switch: only a fresh read sees it.
    DB::table('model_has_permissions')->delete();

    expect(ceiling()->allows($agent->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('gives an instance principal an empty ceiling', function (): void {
    $instance = principals()->instance('instance_service', 'Instance Admin API');

    expect(ceiling()->allows($instance->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

it('reads the ceiling fresh and restores the current team afterwards, also on failure', function (): void {
    $organisationId = (string) Str::uuid();
    $editor = editorIn($organisationId);
    $principal = principals()->forMember($editor, $organisationId);
    $teams = app(TeamContext::class);
    $teams->switchTo('current-organisation');

    expect(ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($teams->currentId())->toBe('current-organisation');

    // Revoked with no Spatie event: only a fresh read sees it.
    DB::table('role_has_permissions')->delete();

    expect(ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();

    // The permission read fails halfway through.
    Schema::drop('model_has_permissions');

    expect(fn () => ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toThrow(QueryException::class)
        ->and($teams->currentId())->toBe('current-organisation');
});

it('gives nothing to a user principal whose user row is gone', function (): void {
    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
    $organisationId = (string) Str::uuid();
    $principal = principals()->forMember(editorIn($organisationId), $organisationId);
    $principal->forceFill(['user_id' => 999_999]);

    expect(ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toBeTrue();

    DB::table('principals')->where('id', $principal->id)->update(['user_id' => null]);

    expect(ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse()
        ->and(Principal::query()->findOrFail($principal->id)->user_id)->toBeNull();
});

it('gives nothing when the users model is not authenticatable', function (): void {
    $organisationId = (string) Str::uuid();
    $principal = principals()->forMember(editorIn($organisationId), $organisationId);
    config()->set('auth.providers.users.model', PlainUserRow::class);

    expect(ceiling()->allows($principal->id, FakePermissionEnum::CanShowUsers->value))->toBeFalse();
});

/**
 * A users table model that is not Authenticatable (a misconfigured provider).
 */
class PlainUserRow extends Model
{
    protected $table = 'users';
}

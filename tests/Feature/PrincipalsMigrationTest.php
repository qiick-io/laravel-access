<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The package create_principals_table migration (design §15.2 / §15.3) on the
 * configured engine; CI runs the `engines` group on PostgreSQL, MySQL and
 * MariaDB too. The generated organisation_key column closes the NULL hole in
 * the membership unique index on every engine (P1); the organisation CHECK and
 * the instance singleton apply only when the product requires organisations (P2,
 * P3), and the config is read when the migration runs (P9).
 */

beforeEach(function (): void {
    Schema::dropIfExists('principals');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    DB::table('users')->insert([['id' => 1, 'email' => 'a@example.com'], ['id' => 2, 'email' => 'b@example.com']]);
});

afterEach(function (): void {
    Schema::dropIfExists('principals');
    Schema::dropIfExists('users');
    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
});

function principalsMigration(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/principals/2026_11_24_100000_create_principals_table.php';
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertPrincipal(array $attributes): string
{
    $id = (string) Str::uuid7();

    DB::table('principals')->insert([
        'id' => $id,
        'type' => 'user',
        'user_id' => null,
        'organisation_id' => null,
        'name' => 'Principal',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    return $id;
}

it('creates the principals table with the design columns', function (): void {
    principalsMigration()->up();

    expect(Schema::hasColumns('principals', [
        'id', 'type', 'user_id', 'organisation_id', 'owner_principal_id', 'name', 'created_by',
        'expires_at', 'deactivated_at', 'deactivation_reason', 'metadata', 'created_at', 'updated_at',
        'organisation_key', 'instance_type',
    ]))->toBeTrue()
        ->and(collect(Schema::getIndexes('principals'))->pluck('name'))->toContain('principals_membership_unique');

    principalsMigration()->down();

    expect(Schema::hasTable('principals'))->toBeFalse();
})->group('engines');

it('stores the generated organisation and instance keys', function (): void {
    principalsMigration()->up();
    $organisationId = (string) Str::uuid();

    $memberId = insertPrincipal(['user_id' => 1, 'organisation_id' => $organisationId]);
    $instanceUserId = insertPrincipal(['user_id' => 2]);
    $serviceId = insertPrincipal(['type' => 'service', 'name' => 'Instance service']);

    $rows = DB::table('principals')->get(['id', 'organisation_key', 'instance_type'])->keyBy('id');

    expect($rows[$memberId]->organisation_key)->toBe($organisationId)
        ->and($rows[$memberId]->instance_type)->toBeNull()
        ->and($rows[$instanceUserId]->organisation_key)->toBe('')
        ->and($rows[$instanceUserId]->instance_type)->toBe('user')
        ->and($rows[$serviceId]->organisation_key)->toBe('')
        ->and($rows[$serviceId]->instance_type)->toBe('service');
})->group('engines');

it('allows one principal per user and organisation, and one per user at instance level (P1)', function (): void {
    principalsMigration()->up();
    $organisationId = (string) Str::uuid();

    insertPrincipal(['user_id' => 1, 'organisation_id' => $organisationId]);
    insertPrincipal(['user_id' => 1]);
    insertPrincipal(['user_id' => 2, 'organisation_id' => $organisationId]);
    insertPrincipal(['user_id' => 1, 'organisation_id' => (string) Str::uuid()]);

    // Services and agents have no user: any number per organisation.
    insertPrincipal(['type' => 'service', 'organisation_id' => $organisationId]);
    insertPrincipal(['type' => 'service', 'organisation_id' => $organisationId]);

    expect(fn () => insertPrincipal(['user_id' => 1, 'organisation_id' => $organisationId]))
        ->toThrow(QueryException::class)
        ->and(fn () => insertPrincipal(['user_id' => 1]))
        ->toThrow(QueryException::class)
        ->and(DB::table('principals')->count())->toBe(6);
})->group('engines');

it('accepts organisation-less principals when organisations are not required', function (): void {
    principalsMigration()->up();

    insertPrincipal(['type' => 'service']);
    insertPrincipal(['type' => 'service']);
    insertPrincipal(['type' => 'agent', 'user_id' => 1]);

    expect(DB::table('principals')->count())->toBe(3);
})->group('engines');

it('enforces the organisation rule and the instance singleton when organisations are required (P2, P3)', function (): void {
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service']);
    principalsMigration()->up();
    $organisationId = (string) Str::uuid();

    $memberId = insertPrincipal(['user_id' => 1, 'organisation_id' => $organisationId]);
    insertPrincipal(['type' => 'service', 'organisation_id' => $organisationId]);
    insertPrincipal(['type' => 'instance_service', 'name' => 'Instance Admin API']);

    $forbiddenRows = [
        'member without organisation' => ['user_id' => 2],
        'service without organisation' => ['type' => 'service'],
        'instance type with an organisation' => ['type' => 'instance_service', 'organisation_id' => $organisationId],
        'second instance service' => ['type' => 'instance_service', 'name' => 'Second'],
    ];

    foreach ($forbiddenRows as $label => $attributes) {
        expect(fn () => insertPrincipal($attributes))->toThrow(QueryException::class, null, $label);
    }

    expect(fn () => DB::table('principals')->where('id', $memberId)->update(['organisation_id' => null]))
        ->toThrow(QueryException::class)
        ->and(DB::table('principals')->count())->toBe(3);
})->group('engines');

it('refuses an instance principal with a user where the engine allows that CHECK', function (): void {
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service']);
    principalsMigration()->up();

    $insert = fn () => insertPrincipal(['type' => 'instance_service', 'user_id' => 2]);

    // MySQL and MariaDB refuse user_id (an ON DELETE SET NULL column) in a CHECK.
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $insert();

        expect(DB::table('principals')->count())->toBe(1);

        return;
    }

    expect($insert)->toThrow(QueryException::class);
})->group('engines');

it('requires an organisation for every type when no instance type is declared', function (): void {
    config()->set('access.principals.organisation_required', true);
    principalsMigration()->up();

    insertPrincipal(['type' => 'service', 'organisation_id' => (string) Str::uuid()]);

    expect(fn () => insertPrincipal(['type' => 'service']))->toThrow(QueryException::class)
        ->and(fn () => insertPrincipal(['type' => 'instance_service']))->toThrow(QueryException::class);
})->group('engines');

it('refuses instance types that are not plain type names', function (mixed $instanceTypes): void {
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', $instanceTypes);

    expect(fn () => principalsMigration()->up())->toThrow(InvalidArgumentException::class, 'access.principals.instance_types')
        ->and(Schema::hasTable('principals'))->toBeFalse();
})->with([
    'quote injection' => [["x') OR ('1'='1"]],
    'upper case' => [['Instance_Service']],
    'too long' => [[str_repeat('a', 33)]],
    'empty' => [['']],
    'not a string' => [[42]],
    'not a list' => ['instance_service'],
]);

it('keeps principals when their user or owner is deleted', function (): void {
    principalsMigration()->up();

    $ownerId = insertPrincipal(['user_id' => 1, 'created_by' => 2]);
    $agentId = insertPrincipal(['type' => 'agent', 'owner_principal_id' => $ownerId, 'created_by' => 1]);

    DB::table('users')->where('id', 1)->delete();
    DB::table('principals')->where('id', $ownerId)->delete();

    $agent = DB::table('principals')->where('id', $agentId)->first();

    expect($agent)->not->toBeNull()
        ->and($agent->owner_principal_id)->toBeNull()
        ->and($agent->created_by)->toBeNull()
        ->and(fn () => insertPrincipal(['user_id' => 99]))->toThrow(QueryException::class);
})->group('engines');

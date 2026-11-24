<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Testing\AssertsPrincipalsSchema;

/*
 * The §15.6 conformance probe: the principals table has the package shape and
 * the database itself refuses a second membership principal, an
 * organisation-less principal when organisations are required, and a second
 * instance principal (P1, P2, P3, P9), on the engine CI runs it on.
 */

beforeEach(function (): void {
    Schema::dropIfExists('principals');
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('principals');
    Schema::dropIfExists('users');
    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
});

function migratePrincipalsTable(bool $isOrganisationRequired, array $instanceTypes = []): void
{
    config()->set('access.principals.organisation_required', $isOrganisationRequired);
    config()->set('access.principals.instance_types', $instanceTypes);

    (require dirname(__DIR__, 2).'/database/migrations/principals/2026_11_24_100000_create_principals_table.php')->up();
}

function principalsProbe(): AssertsPrincipalsSchema
{
    return new AssertsPrincipalsSchema(DB::connection(), static fn (): int => DB::table('users')->insertGetId(['email' => uniqid('probe', true)]));
}

it('passes on the package migration and leaves no rows behind', function (bool $isOrganisationRequired, array $instanceTypes): void {
    migratePrincipalsTable($isOrganisationRequired, $instanceTypes);

    principalsProbe()->assertShape();
    principalsProbe()->assertConstraints();

    expect(DB::table('principals')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(0);
})->with([
    'single tenant' => [false, []],
    'organisations required' => [true, []],
    'organisations required with an instance type' => [true, ['instance_service']],
])->group('engines');

const PRINCIPAL_COLUMNS = [
    'id', 'type', 'user_id', 'organisation_id', 'owner_principal_id', 'name', 'created_by', 'expires_at',
    'deactivated_at', 'deactivation_reason', 'metadata', 'created_at', 'updated_at', 'organisation_key', 'instance_type',
];

it('fails when the table is missing', function (): void {
    expect(fn () => principalsProbe()->assertShape())->toThrow(RuntimeException::class, 'expected table [principals]');
});

it('fails when a design column is missing', function (string $missingColumn): void {
    Schema::create('principals', function (Blueprint $table) use ($missingColumn): void {
        foreach (array_diff(PRINCIPAL_COLUMNS, [$missingColumn]) as $column) {
            $table->string($column)->nullable();
        }
    });

    expect(fn () => principalsProbe()->assertShape())->toThrow(RuntimeException::class, "expected column [principals.{$missingColumn}]");
})->with(PRINCIPAL_COLUMNS);

it('fails on the NULL hole of a unique index on (organisation_id, user_id) (P1)', function (): void {
    Schema::create('principals', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type', 32);
        $table->foreignId('user_id')->nullable()->constrained('users');
        $table->uuid('organisation_id')->nullable();
        $table->string('name');
        $table->unique(['organisation_id', 'user_id']);
    });

    expect(fn () => principalsProbe()->assertConstraints())->toThrow(RuntimeException::class, 'second instance-level principal for the same user');
})->group('engines');

it('fails when a second membership principal is accepted (P1)', function (): void {
    migratePrincipalsTable(false);
    Schema::table('principals', function (Blueprint $table): void {
        $table->dropUnique('principals_membership_unique');
    });

    expect(fn () => principalsProbe()->assertConstraints())->toThrow(RuntimeException::class, 'second principal for the same user and organisation');
})->group('engines');

it('fails when the config requires organisations but the schema does not enforce them (P2, P9)', function (): void {
    migratePrincipalsTable(false);
    config()->set('access.principals.organisation_required', true);

    expect(fn () => principalsProbe()->assertConstraints())->toThrow(RuntimeException::class, 'without an organisation');
})->group('engines');

it('fails when a declared instance type is not a singleton (P3, P9)', function (): void {
    migratePrincipalsTable(true, ['instance_service']);
    Schema::table('principals', function (Blueprint $table): void {
        $table->dropUnique('principals_instance_singleton');
    });

    expect(fn () => principalsProbe()->assertConstraints())->toThrow(RuntimeException::class, 'second [instance_service]');
})->group('engines');

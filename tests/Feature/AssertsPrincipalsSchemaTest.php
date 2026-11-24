<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Laravel\AccessSchema;
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

it('fails when a user with a principal can be deleted', function (): void {
    Schema::create('principals', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type', 32);
        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->uuid('organisation_id')->nullable();
        $table->string('name');
        $table->unique(['organisation_id', 'user_id']);
    });

    expect(fn () => principalsProbe()->assertConstraints())->toThrow(RuntimeException::class, 'must be ON DELETE RESTRICT');
})->group('engines');

it('fails when an instance type may have a user (review B1)', function (): void {
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service']);
    Schema::create('principals', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type', 32);
        $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->uuid('organisation_id')->nullable();
        $table->string('name');
        $table->unique(['organisation_id', 'user_id']);
    });
    // The organisation rule without its user clause.
    $ruleWithoutUser = "(type IN ('instance_service') AND organisation_id IS NULL) OR (type NOT IN ('instance_service') AND organisation_id IS NOT NULL)";

    if (DB::connection()->getDriverName() === 'sqlite') {
        $triggerRule = (string) preg_replace('/\\b(type|organisation_id)\\b/', 'NEW.$1', $ruleWithoutUser);
        DB::statement("CREATE TRIGGER rule_without_user BEFORE INSERT ON principals FOR EACH ROW WHEN NOT ({$triggerRule}) BEGIN SELECT RAISE(ABORT, 'rule'); END");
    } else {
        DB::statement("ALTER TABLE principals ADD CONSTRAINT rule_without_user CHECK ({$ruleWithoutUser})");
    }

    expect(fn () => principalsProbe()->assertConstraints())->toThrow(RuntimeException::class, 'instance principal with a user');
})->group('engines');

// Re-review of #17.

/**
 * The product's organisations table with the restrict foreign key the design
 * requires (§15.5), and a probe that creates and deletes real organisations.
 */
function principalsWithOrganisationForeignKey(bool $isRestrict = true): AssertsPrincipalsSchema
{
    Schema::create('organisations', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    migratePrincipalsTable(true, ['instance_service']);
    Schema::table('principals', function (Blueprint $table) use ($isRestrict): void {
        $foreign = $table->foreign('organisation_id')->references('id')->on('organisations');
        $isRestrict ? $foreign->restrictOnDelete() : $foreign->cascadeOnDelete();
    });
    AccessSchema::ensureConstraints();

    return new AssertsPrincipalsSchema(
        DB::connection(),
        static fn (): int => DB::table('users')->insertGetId(['email' => uniqid('probe', true)]),
        static function (): string {
            $organisationId = (string) Str::uuid();
            DB::table('organisations')->insert(['id' => $organisationId]);

            return $organisationId;
        },
        static function (string $organisationId): void {
            DB::table('organisations')->where('id', $organisationId)->delete();
        },
    );
}

it('runs with the product organisation foreign key, using real organisations (review R2)', function (): void {
    $probe = principalsWithOrganisationForeignKey();

    $probe->assertShape();
    $probe->assertConstraints();

    expect(DB::table('principals')->count())->toBe(0)
        ->and(DB::table('organisations')->count())->toBe(0);

    Schema::dropIfExists('principals');
    Schema::dropIfExists('organisations');
})->group('engines');

it('fails when an organisation with principals can be deleted (§15.6, P6)', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        // MySQL and MariaDB refuse a cascade on a base column of organisation_key.
        $this->markTestSkipped('MySQL and MariaDB refuse this misconfiguration themselves.');
    }

    $probe = principalsWithOrganisationForeignKey(false);

    expect(fn () => $probe->assertConstraints())->toThrow(RuntimeException::class, 'deleted an organisation that still has principals');

    Schema::dropIfExists('principals');
    Schema::dropIfExists('organisations');
})->group('engines');

/**
 * A connection that refuses the probe's forbidden writes with the given
 * driver error: a principal row repeating an earlier one (ids aside), and
 * deleting a user. Every other write goes through.
 *
 * @param  array{0: string, 1: int, 2: string}  $errorInfo
 */
function connectionRefusingWith(array $errorInfo): Connection
{
    $failure = static function () use ($errorInfo): never {
        $driverError = new PDOException($errorInfo[2], $errorInfo[1]);
        $driverError->errorInfo = $errorInfo;

        throw new QueryException('testing', 'write', [], $driverError);
    };
    $insertedRows = [];
    $connection = Mockery::mock(DB::connection())->makePartial();
    $connection->shouldReceive('table')->with('principals')->andReturnUsing(static function () use ($failure, &$insertedRows): object {
        return new class($failure, $insertedRows)
        {
            /**
             * @param  list<array<string, mixed>>  $insertedRows
             */
            public function __construct(private Closure $failure, private array &$insertedRows) {}

            /**
             * @param  array<string, mixed>  $row
             */
            public function insert(array $row): bool
            {
                unset($row['id']);

                if (in_array($row, $this->insertedRows, true)) {
                    ($this->failure)();
                }

                $this->insertedRows[] = $row;

                return true;
            }
        };
    });
    $connection->shouldReceive('table')->with('users')->andReturnUsing(static fn (): object => new class($failure)
    {
        public function __construct(private Closure $failure) {}

        public function where(mixed ...$arguments): static
        {
            return $this;
        }

        public function delete(): never
        {
            ($this->failure)();
        }
    });

    return $connection;
}

it('does not count a failure unrelated to integrity as a refusal (review N12)', function (): void {
    migratePrincipalsTable(false);
    $probe = new AssertsPrincipalsSchema(connectionRefusingWith(['HY000', 2006, 'server has gone away']), static fn (): int => DB::table('users')->insertGetId(['email' => uniqid('probe', true)]));

    expect(fn () => $probe->assertConstraints())->toThrow(QueryException::class, 'server has gone away');
});

it('counts a MySQL CHECK violation as a refusal although its SQLSTATE is HY000', function (): void {
    migratePrincipalsTable(false);
    $probe = new AssertsPrincipalsSchema(connectionRefusingWith(['HY000', 3819, 'Check constraint is violated']), static fn (): int => DB::table('users')->insertGetId(['email' => uniqid('probe', true)]));

    $probe->assertConstraints();

    expect(DB::table('principals')->count())->toBe(0);
});

it('restores the table rules after every migrate run (review R3)', function (): void {
    migratePrincipalsTable(true, ['instance_service']);
    Schema::create('organisations', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::table('principals', function (Blueprint $table): void {
        $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
    });

    event(new MigrationsEnded('up'));

    expect(fn () => DB::table('principals')->insert(['id' => (string) Str::uuid7(), 'type' => 'service', 'name' => 'No organisation']))
        ->toThrow(QueryException::class);

    Schema::dropIfExists('principals');
    Schema::dropIfExists('organisations');
})->group('engines');

<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Laravel\AccessSchema;
use Qiick\Access\Testing\AssertsGroupTablesSchema;

beforeEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('users');
});

it('passes AC17 when the package migration shape is present', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/groups/2026_11_22_120000_create_user_groups_tables.php';
    $migration->up();

    (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape();
    expect(true)->toBeTrue();
})->group('engines');

it('fails AC17 when a required pivot index is missing', function (): void {
    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->json('metadata')->nullable();
        $table->uuid('organisation_id')->nullable()->index();
        $table->boolean('is_instance')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    // Deliberately omit the XOR constraint / trigger so assertShape fails later
    // if indexes somehow matched; missing expires_at index fails first.
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->dateTime('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['user_group_id', 'user_id']);
        // Missing expires_at and user_id indexes on purpose.
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->unsignedBigInteger('role_id');
        $table->timestamps();
        $table->unique(['user_group_id', 'role_id']);
        $table->index('role_id');
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, 'AC17');
})->group('engines');

it('fails AC17 when a surrogate id appears on a pivot', function (): void {
    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->json('metadata')->nullable();
        $table->uuid('organisation_id')->nullable()->index();
        $table->boolean('is_instance')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    // XOR holds so assertShape reaches the surrogate-id check on the pivot.
    $driver = Schema::getConnection()->getDriverName();

    if ($driver === 'sqlite') {
        DB::statement(
            'CREATE TRIGGER user_groups_instance_org_xor_insert
             BEFORE INSERT ON user_groups
             FOR EACH ROW
             WHEN NEW.is_instance = 1 AND NEW.organisation_id IS NOT NULL
             BEGIN
                 SELECT RAISE(ABORT, \'is_instance requires null organisation_id\');
             END',
        );
    } else {
        $expression = in_array($driver, ['mysql', 'mariadb'], true)
            ? '(is_instance = 0 OR organisation_id IS NULL)'
            : '(is_instance = false OR organisation_id IS NULL)';
        DB::statement(
            "ALTER TABLE user_groups ADD CONSTRAINT user_groups_instance_org_xor CHECK {$expression}",
        );
    }

    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->dateTime('expires_at')->nullable()->index();
        $table->timestamps();
        $table->unique(['user_group_id', 'user_id']);
        $table->index('user_id');
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->unsignedBigInteger('role_id');
        $table->timestamps();
        $table->unique(['user_group_id', 'role_id']);
        $table->index('role_id');
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, 'surrogate');
})->group('engines');

/**
 * Group tables in every way valid except, possibly, the name uniqueness:
 * 'global' is externa 1.x's own table, 'per organisation id' lets NULL
 * duplicates through on every engine.
 */
function createGroupTablesWithNameUniqueness(string $nameUniqueness): void
{
    Schema::create('user_groups', function (Blueprint $table) use ($nameUniqueness): void {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->string('name');
        $table->text('description')->nullable();
        $table->json('metadata')->nullable();
        $table->uuid('organisation_id')->nullable()->index();
        $table->boolean('is_instance')->default(false);
        $table->timestamps();
        $table->softDeletes();

        if ($nameUniqueness === 'global') {
            $table->unique('name');
        } elseif ($nameUniqueness === 'per organisation id') {
            $table->unique(['organisation_id', 'name']);
        } else {
            // The package's index, on a key that keeps NULL instead of ''.
            $table->string('organisation_key', 36)->nullable()->storedAs('organisation_id');
            $table->unique(['organisation_key', 'name']);
        }
    });
    AccessSchema::ensureConstraints();
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->dateTime('expires_at')->nullable()->index();
        $table->timestamps();
        $table->unique(['user_group_id', 'user_id']);
        $table->index('user_id');
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->unsignedBigInteger('role_id');
        $table->timestamps();
        $table->unique(['user_group_id', 'role_id']);
        $table->index('role_id');
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });
}

it('accepts globally unique group names, for products without organisations (externa 1.x)', function (): void {
    createGroupTablesWithNameUniqueness('global');

    (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape();
    expect(true)->toBeTrue();
})->group('engines');

it('fails AC-a when two organisation-less groups may share a name', function (string $nameUniqueness, string $failure): void {
    createGroupTablesWithNameUniqueness($nameUniqueness);

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, $failure)
        ->and(DB::table('user_groups')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
})->with([
    'unique per organisation id' => ['per organisation id', 'AC-a: expected a unique index'],
    'unique per a key that keeps NULL' => ['per nullable key', 'AC-a: two groups without an organisation were saved with the same name.'],
])->group('engines');

/**
 * The valid group tables (with externa's global unique name, which needs no
 * generated column) with exactly one requirement of §2.4–2.6 broken, so each
 * check of the helper has a test that fails without it.
 *
 * @param  string  $break  `<table>.<kind>:<column>`, or '' for the valid shape
 */
function createGroupTablesBreaking(string $break): void
{
    [$brokenTable, $brokenRule] = $break === '' ? ['', ''] : explode('.', $break, 2);
    [$kind, $brokenColumn] = $brokenRule === '' ? ['', ''] : explode(':', $brokenRule, 2);
    $isBroken = static fn (string $table, string $expectedKind, string $column): bool => $brokenTable === $table && $kind === $expectedKind && $brokenColumn === $column;
    $column = static function (Blueprint $table, string $tableName, string $columnName, Closure $define, bool $isNullable) use ($isBroken): void {
        if ($isBroken($tableName, 'missing', $columnName)) {
            return;
        }

        $definition = $define($table);

        if ($isBroken($tableName, 'nullable', $columnName)) {
            $definition->nullable();
        } elseif ($isBroken($tableName, 'notnull', $columnName)) {
            $definition->nullable(false);
        } elseif ($isNullable) {
            $definition->nullable();
        }
    };

    Schema::create('user_groups', function (Blueprint $table) use ($column, $isBroken): void {
        if ($isBroken('user_groups', 'nullable', 'id')) {
            $table->unsignedBigInteger('id')->nullable()->unique();
        } elseif (! $isBroken('user_groups', 'missing', 'id')) {
            $table->id();
        } else {
            $table->bigIncrements('group_key');
        }

        $column($table, 'user_groups', 'public_id', fn (Blueprint $table) => $table->uuid('public_id'), false);
        $column($table, 'user_groups', 'name', fn (Blueprint $table) => $table->string('name'), false);
        $column($table, 'user_groups', 'description', fn (Blueprint $table) => $table->text('description'), true);
        $column($table, 'user_groups', 'metadata', fn (Blueprint $table) => $table->json('metadata'), true);
        $column($table, 'user_groups', 'organisation_id', fn (Blueprint $table) => $table->uuid('organisation_id'), true);
        $column($table, 'user_groups', 'is_instance', fn (Blueprint $table) => $table->boolean('is_instance')->default(false), false);
        $column($table, 'user_groups', 'created_at', fn (Blueprint $table) => $table->timestamp('created_at'), true);
        $column($table, 'user_groups', 'updated_at', fn (Blueprint $table) => $table->timestamp('updated_at'), true);
        $column($table, 'user_groups', 'deleted_at', fn (Blueprint $table) => $table->timestamp('deleted_at'), true);

        if ($isBroken('user_groups', 'extra', 'required')) {
            $table->string('required_extra');
        }

        if ($isBroken('user_groups', 'plain', 'public_id')) {
            $table->index('public_id');
        } elseif (! $isBroken('user_groups', 'missing', 'public_id')) {
            $table->unique('public_id');
        }

        if ($isBroken('user_groups', 'plain', 'name')) {
            $table->index('name');
        } elseif (! $isBroken('user_groups', 'missing', 'name')) {
            $table->unique('name');
        }

        if (! $isBroken('user_groups', 'unindexed', 'organisation_id') && ! $isBroken('user_groups', 'missing', 'organisation_id')) {
            $table->index('organisation_id');
        }
    });

    if (! $isBroken('user_groups', 'unchecked', 'is_instance') && ! $isBroken('user_groups', 'missing', 'is_instance') && ! $isBroken('user_groups', 'missing', 'organisation_id')) {
        AccessSchema::ensureConstraints();
    }

    Schema::create('user_group_user', function (Blueprint $table) use ($column, $isBroken): void {
        if ($isBroken('user_group_user', 'surrogate', 'id')) {
            $table->id();
        }

        $column($table, 'user_group_user', 'user_group_id', fn (Blueprint $table) => $table->unsignedBigInteger('user_group_id'), false);
        $column($table, 'user_group_user', 'user_id', fn (Blueprint $table) => $table->unsignedBigInteger('user_id'), false);
        $column($table, 'user_group_user', 'expires_at', fn (Blueprint $table) => $table->dateTime('expires_at'), true);
        $column($table, 'user_group_user', 'created_at', fn (Blueprint $table) => $table->timestamp('created_at'), true);
        $column($table, 'user_group_user', 'updated_at', fn (Blueprint $table) => $table->timestamp('updated_at'), true);

        if (! $isBroken('user_group_user', 'missing', 'user_group_id') && ! $isBroken('user_group_user', 'missing', 'user_id')) {
            $isBroken('user_group_user', 'plain', 'user_group_id,user_id')
                ? $table->index(['user_group_id', 'user_id'])
                : $table->unique(['user_group_id', 'user_id']);
        }

        if (! $isBroken('user_group_user', 'unindexed', 'user_id') && ! $isBroken('user_group_user', 'missing', 'user_id')) {
            $table->index('user_id');
        }

        if (! $isBroken('user_group_user', 'unindexed', 'expires_at') && ! $isBroken('user_group_user', 'missing', 'expires_at')) {
            $table->index('expires_at');
        }

        if (! $isBroken('user_group_user', 'missing', 'user_group_id') && ! $isBroken('user_group_user', 'unconstrained', 'user_group_id')) {
            $table->foreign('user_group_id')->references('id')->on('user_groups')
                ->onDelete($isBroken('user_group_user', 'restrict', 'user_group_id') ? 'restrict' : 'cascade');
        }

        if (! $isBroken('user_group_user', 'missing', 'user_id')) {
            $table->foreign('user_id')->references('id')->on($isBroken('user_group_user', 'elsewhere', 'user_id') ? 'roles' : 'users')
                ->onDelete($isBroken('user_group_user', 'restrict', 'user_id') ? 'restrict' : 'cascade');
        }
    });

    Schema::create('user_group_role', function (Blueprint $table) use ($column, $isBroken): void {
        if ($isBroken('user_group_role', 'surrogate', 'id')) {
            $table->id();
        }

        $column($table, 'user_group_role', 'user_group_id', fn (Blueprint $table) => $table->unsignedBigInteger('user_group_id'), false);
        $column($table, 'user_group_role', 'role_id', fn (Blueprint $table) => $table->unsignedBigInteger('role_id'), false);
        $column($table, 'user_group_role', 'created_at', fn (Blueprint $table) => $table->timestamp('created_at'), true);
        $column($table, 'user_group_role', 'updated_at', fn (Blueprint $table) => $table->timestamp('updated_at'), true);

        if (! $isBroken('user_group_role', 'missing', 'user_group_id') && ! $isBroken('user_group_role', 'missing', 'role_id')) {
            $isBroken('user_group_role', 'plain', 'user_group_id,role_id')
                ? $table->index(['user_group_id', 'role_id'])
                : $table->unique(['user_group_id', 'role_id']);
        }

        if (! $isBroken('user_group_role', 'unindexed', 'role_id') && ! $isBroken('user_group_role', 'missing', 'role_id')) {
            $table->index('role_id');
        }

        if (! $isBroken('user_group_role', 'missing', 'user_group_id')) {
            $table->foreign('user_group_id')->references('id')->on('user_groups')
                ->onDelete($isBroken('user_group_role', 'restrict', 'user_group_id') ? 'restrict' : 'cascade');
        }

        if (! $isBroken('user_group_role', 'missing', 'role_id')) {
            $table->foreign('role_id')->references('id')->on('roles')
                ->onDelete($isBroken('user_group_role', 'restrict', 'role_id') ? 'restrict' : 'cascade');
        }
    });
}

it('accepts the valid shape built by the breakage helper, leaving no probe row behind', function (): void {
    createGroupTablesBreaking('');

    (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape();

    expect(DB::table('user_groups')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('accepts NOT NULL timestamps, which the probe rows fill (AC17)', function (string $column): void {
    createGroupTablesBreaking("user_groups.notnull:{$column}");

    (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape();

    expect(DB::table('user_groups')->count())->toBe(0);
})->with(['created_at', 'updated_at']);

it('never takes an unrelated refusal of the probe row for the rule (AC17)', function (): void {
    createGroupTablesBreaking('user_groups.extra:required');

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, 'AC17: user_groups refused a valid probe row, so the probe proves nothing')
        ->and(DB::table('user_groups')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('leaves no probe row behind when a probe insert is accepted (AC17, AC-a)', function (string $break, string $failure): void {
    createGroupTablesBreaking($break);

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())->toThrow(RuntimeException::class, $failure)
        ->and(DB::table('user_groups')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
})->with([
    'instance rule missing' => ['user_groups.unchecked:is_instance', 'AC17/C75'],
    'name not unique' => ['user_groups.plain:name', 'AC-a: expected a unique index'],
]);

it('fails on each broken requirement of the group tables with its own message (AC17)', function (string $break, string $failure): void {
    createGroupTablesBreaking($break);

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, $failure);
})->with(function (): array {
    $cases = [];

    foreach (['id', 'public_id', 'name', 'description', 'metadata', 'organisation_id', 'is_instance', 'created_at', 'updated_at', 'deleted_at'] as $column) {
        $cases["user_groups without {$column}"] = ["user_groups.missing:{$column}", "AC17: expected column [user_groups.{$column}]"];
    }

    foreach (['user_group_id', 'user_id', 'expires_at', 'created_at', 'updated_at'] as $column) {
        $cases["user_group_user without {$column}"] = ["user_group_user.missing:{$column}", "AC17: expected column [user_group_user.{$column}]"];
    }

    foreach (['user_group_id', 'role_id', 'created_at', 'updated_at'] as $column) {
        $cases["user_group_role without {$column}"] = ["user_group_role.missing:{$column}", "AC17: expected column [user_group_role.{$column}]"];
    }

    foreach (['user_groups' => ['id', 'public_id', 'name', 'is_instance'], 'user_group_user' => ['user_group_id', 'user_id'], 'user_group_role' => ['user_group_id', 'role_id']] as $table => $columns) {
        foreach ($columns as $column) {
            $cases["nullable {$table}.{$column}"] = ["{$table}.nullable:{$column}", "AC17: column [{$table}.{$column}] must not be nullable"];
        }
    }

    foreach (['user_groups' => ['description', 'metadata', 'organisation_id', 'deleted_at'], 'user_group_user' => ['expires_at']] as $table => $columns) {
        foreach ($columns as $column) {
            $cases["not nullable {$table}.{$column}"] = ["{$table}.notnull:{$column}", "AC17: column [{$table}.{$column}] must be nullable"];
        }
    }

    return [
        ...$cases,
        'public_id not unique' => ['user_groups.plain:public_id', 'AC17: expected unique index on [user_groups.public_id]'],
        'organisation_id not indexed' => ['user_groups.unindexed:organisation_id', 'AC17: expected index on [user_groups.organisation_id]'],
        'instance rule missing' => ['user_groups.unchecked:is_instance', 'AC17/C75: expected CHECK that is_instance=true only when organisation_id IS NULL'],
        'membership surrogate id' => ['user_group_user.surrogate:id', 'AC17: column [user_group_user.id] must be absent'],
        'membership not unique' => ['user_group_user.plain:user_group_id,user_id', 'AC17: expected unique index on [user_group_user.user_group_id,user_id]'],
        'membership user_id not indexed' => ['user_group_user.unindexed:user_id', 'AC17: expected index on [user_group_user.user_id]'],
        'membership expires_at not indexed' => ['user_group_user.unindexed:expires_at', 'AC17: expected index on [user_group_user.expires_at]'],
        'membership group FK restrict' => ['user_group_user.restrict:user_group_id', 'AC17: [user_group_user.user_group_id] FK onDelete must be cascade, got [restrict]'],
        'membership group FK missing' => ['user_group_user.unconstrained:user_group_id', 'AC17: expected cascade FK [user_group_user.user_group_id] → [user_groups]'],
        'membership user FK restrict' => ['user_group_user.restrict:user_id', 'AC17: [user_group_user.user_id] FK onDelete must be cascade, got [restrict]'],
        'membership user FK elsewhere' => ['user_group_user.elsewhere:user_id', 'AC17: [user_group_user.user_id] must reference [users], got [roles]'],
        'role surrogate id' => ['user_group_role.surrogate:id', 'AC17: column [user_group_role.id] must be absent'],
        'role link not unique' => ['user_group_role.plain:user_group_id,role_id', 'AC17: expected unique index on [user_group_role.user_group_id,role_id]'],
        'role_id not indexed' => ['user_group_role.unindexed:role_id', 'AC17: expected index on [user_group_role.role_id]'],
        'role group FK restrict' => ['user_group_role.restrict:user_group_id', 'AC17: [user_group_role.user_group_id] FK onDelete must be cascade, got [restrict]'],
        'role FK restrict' => ['user_group_role.restrict:role_id', 'AC17: [user_group_role.role_id] FK onDelete must be cascade, got [restrict]'],
    ];
});

it('reads the users and roles tables the product configured (AC17)', function (): void {
    createGroupTablesBreaking('');
    $helper = new AssertsGroupTablesSchema(app('db')->connection());

    config()->set('access.users_table', 'members');
    expect(fn () => $helper->assertShape())->toThrow(RuntimeException::class, 'AC17: [user_group_user.user_id] must reference [members], got [users]');

    config()->set('access.users_table', '');
    config()->set('auth.providers.users.model', SpatieMemberUser::class);
    expect(fn () => $helper->assertShape())->toThrow(RuntimeException::class, 'must reference [members], got [users]');

    config()->set('auth.providers.users.model', 'NoSuchUserModel');
    $helper->assertShape();

    config()->set('auth.providers.users.model', stdClass::class);
    $helper->assertShape();

    config()->set('permission.table_names.roles', 'access_roles');
    expect(fn () => $helper->assertShape())->toThrow(RuntimeException::class, 'AC17: [user_group_role.role_id] must reference [access_roles], got [roles]');

    config()->set('permission.table_names.roles', '');
    $helper->assertShape();

    config()->set('access.users_table', null);
    config()->set('auth.providers.users.model', null);
    config()->set('permission.table_names.roles', 'roles');
});

it('fails when a group table is missing (AC17)', function (string $table): void {
    createGroupTablesBreaking('');
    Schema::drop($table);

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, "AC17: expected table [{$table}] to exist");
})->with(['user_group_role', 'user_group_user']);

it('fails when the user_groups table is missing (AC17)', function (): void {
    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, 'AC17: expected table [user_groups] to exist');
});

/**
 * A users model on another table, for the users-table fallback.
 */
final class SpatieMemberUser extends Model
{
    protected $table = 'members';
}

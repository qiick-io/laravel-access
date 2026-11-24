<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\Principal;
use Qiick\Access\Principals\PrincipalCreated;
use Qiick\Access\Principals\PrincipalDeactivated;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\Access\Tests\Support\SpatieUser;

/*
 * PrincipalDirectory (design §15.4) on the configured engine, with the
 * security cases of §15.7 (P2–P8).
 */

function migratePrincipals(bool $isOrganisationRequired = false, array $instanceTypes = []): void
{
    config()->set('access.principals.organisation_required', $isOrganisationRequired);
    config()->set('access.principals.instance_types', $instanceTypes);

    (require dirname(__DIR__, 3).'/database/migrations/principals/2026_11_24_100000_create_principals_table.php')->up();
    app()->forgetInstance(PrincipalDirectory::class);
}

beforeEach(function (): void {
    config()->set('auth.providers.users.model', SpatieUser::class);
    Schema::dropIfExists('principals');
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });
    DB::table('users')->insert([['id' => 1, 'email' => 'a@example.com'], ['id' => 2, 'email' => 'b@example.com']]);
});

afterEach(function (): void {
    config()->set('access.principals.model', Principal::class);
    config()->set('access.principals.types', ['user', 'service', 'agent']);
    Schema::dropIfExists('principals');
    Schema::dropIfExists('users');
    config()->set('access.principals.organisation_required', false);
    config()->set('access.principals.instance_types', []);
});

function directory(): PrincipalDirectory
{
    return app(PrincipalDirectory::class);
}

function member(int $id = 1): SpatieUser
{
    return SpatieUser::query()->findOrFail($id);
}

it('creates one membership principal per user and organisation, idempotently', function (): void {
    migratePrincipals();
    Event::fake([PrincipalCreated::class]);
    $organisationId = (string) Str::uuid();

    $principal = directory()->forMember(member(), $organisationId);

    expect($principal->type)->toBe('user')
        ->and($principal->user_id)->toBe(1)
        ->and($principal->organisation_id)->toBe($organisationId)
        ->and($principal->name)->toBe('a@example.com')
        ->and(Str::isUuid($principal->id))->toBeTrue()
        ->and(directory()->forMember(member(), $organisationId)->id)->toBe($principal->id)
        ->and(directory()->forMember(member(), null)->id)->not->toBe($principal->id)
        ->and(Principal::query()->count())->toBe(2);

    Event::assertDispatchedTimes(PrincipalCreated::class, 2);
})->group('engines');

it('refuses an organisation-less membership when organisations are required (P2)', function (): void {
    migratePrincipals(true);

    expect(fn () => directory()->forMember(member(), null))->toThrow(InvalidArgumentException::class, 'organisation')
        ->and(Principal::query()->count())->toBe(0);
});

it('reactivates a deactivated membership principal unless it was erased', function (): void {
    migratePrincipals();
    $organisationId = (string) Str::uuid();
    $principal = directory()->forMember(member(), $organisationId);

    directory()->deactivate($principal, DeactivationReason::Offboarded);
    $reactivated = directory()->forMember(member(), $organisationId);

    expect($reactivated->id)->toBe($principal->id)
        ->and($reactivated->deactivated_at)->toBeNull()
        ->and($reactivated->deactivation_reason)->toBeNull()
        ->and(directory()->isActive($principal->id))->toBeTrue();

    directory()->deactivate($reactivated, DeactivationReason::Erased);

    expect(fn () => directory()->forMember(member(), $organisationId))->toThrow(LogicException::class, 'erased')
        ->and(directory()->isActive($principal->id))->toBeFalse();
});

it('creates services and agents with owner, creator and metadata', function (): void {
    migratePrincipals(true, ['instance_service']);
    Event::fake([PrincipalCreated::class]);
    $organisationId = (string) Str::uuid();
    $owner = directory()->forMember(member(), $organisationId);

    $agent = directory()->createService('agent', $organisationId, 'Report bot', $owner, member(2), ['purpose' => 'reports']);

    expect($agent->type)->toBe('agent')
        ->and($agent->organisation_id)->toBe($organisationId)
        ->and($agent->owner_principal_id)->toBe($owner->id)
        ->and($agent->created_by)->toBe(2)
        ->and($agent->user_id)->toBeNull()
        ->and($agent->metadata)->toBe(['purpose' => 'reports'])
        ->and($agent->owner->is($owner))->toBeTrue()
        ->and($owner->owned->pluck('id')->all())->toBe([$agent->id]);

    Event::assertDispatched(PrincipalCreated::class, fn (PrincipalCreated $event): bool => $event->principal->is($agent));
});

it('refuses service principals that would escape the organisation or the type list (P2)', function (string $type, ?string $organisationId, string $message): void {
    migratePrincipals(true, ['instance_service']);

    expect(fn () => directory()->createService($type, $organisationId, 'Bad'))->toThrow(InvalidArgumentException::class, $message)
        ->and(Principal::query()->count())->toBe(0);
})->with([
    'no organisation when required' => ['service', null, 'organisation'],
    'instance type' => ['instance_service', null, 'instance()'],
    'membership type' => ['user', '0195f0e0-0000-7000-8000-000000000001', 'forMember()'],
    'unknown type' => ['robot', '0195f0e0-0000-7000-8000-000000000001', 'access.principals.types'],
]);

it('allows an organisation-less service when organisations are not required', function (): void {
    migratePrincipals();

    expect(directory()->createService('service', null, 'Importer')->isInstanceLevel())->toBeTrue();
});

it('refuses an owner whose chain is cyclic or deeper than eight (P8)', function (): void {
    migratePrincipals();
    $chain = [directory()->createService('agent', null, 'Root')];

    foreach (range(1, 7) as $level) {
        $chain[] = directory()->createService('agent', null, "Level {$level}", $chain[array_key_last($chain)]);
    }

    expect(directory()->isActive($chain[7]->id))->toBeTrue()
        ->and(fn () => directory()->createService('agent', null, 'Too deep', $chain[7]))
        ->toThrow(InvalidArgumentException::class, 'owner chain');

    DB::table('principals')->where('id', $chain[0]->id)->update(['owner_principal_id' => $chain[2]->id]);

    expect(fn () => directory()->createService('agent', null, 'Cycle', $chain[2]))
        ->toThrow(InvalidArgumentException::class, 'owner chain')
        ->and(directory()->isActive($chain[2]->id))->toBeFalse();
});

it('keeps instance principals singletons and only for declared types (P3)', function (): void {
    migratePrincipals(true, ['instance_service']);
    Event::fake([PrincipalCreated::class]);

    $instance = directory()->instance('instance_service', 'Instance Admin API');

    expect($instance->isInstanceLevel())->toBeTrue()
        ->and($instance->user_id)->toBeNull()
        ->and(directory()->instance('instance_service', 'Other name')->id)->toBe($instance->id)
        ->and(fn () => directory()->instance('service', 'Not declared'))->toThrow(InvalidArgumentException::class, 'instance_types');

    Event::assertDispatchedTimes(PrincipalCreated::class, 1);
});

it('keeps one instance principal when another worker races to create it (P3)', function (): void {
    $defaultConnection = config('database.default');
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database cannot be shared with a second connection.');
    }

    migratePrincipals(true, ['instance_service']);
    // A second connection to the same database plays the concurrent worker.
    config()->set('database.connections.concurrent_worker', config("database.connections.{$defaultConnection}"));
    $worker = DB::connection('concurrent_worker');
    $isWorkerBlocked = false;

    if ($driver !== 'pgsql') {
        $worker->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    // The other worker tries to commit the singleton between our locked read and our insert.
    Principal::creating(function () use (&$isWorkerBlocked, $worker): void {
        try {
            $worker->table('principals')->insert([
                'id' => (string) Str::uuid7(), 'type' => 'instance_service', 'name' => 'Winner',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $isWorkerBlocked = true;
        }
    });

    $instance = directory()->instance('instance_service', 'Loser');

    expect(Principal::query()->count())->toBe(1);

    if ($driver === 'pgsql') {
        // No gap lock: the worker commits first and our insert returns its row.
        expect($instance->name)->toBe('Winner')->and($isWorkerBlocked)->toBeFalse();

        return;
    }

    // InnoDB: the locked read's gap lock holds the worker until we commit.
    expect($instance->name)->toBe('Loser')->and($isWorkerBlocked)->toBeTrue();
})->group('engines');

it('deactivates a principal and everything it owns, in one transaction', function (): void {
    migratePrincipals();
    Event::fake([PrincipalDeactivated::class]);
    $owner = directory()->forMember(member(), null);
    $agent = directory()->createService('agent', null, 'Agent', $owner);
    $subAgent = directory()->createService('agent', null, 'Sub-agent', $agent);
    $unrelated = directory()->createService('service', null, 'Unrelated');

    directory()->deactivate($owner, DeactivationReason::Offboarded);

    $rows = Principal::query()->get()->keyBy('id');

    expect($rows[$owner->id]->deactivation_reason)->toBe(DeactivationReason::Offboarded)
        ->and($rows[$agent->id]->deactivation_reason)->toBe(DeactivationReason::OwnerDeactivated)
        ->and($rows[$subAgent->id]->deactivation_reason)->toBe(DeactivationReason::OwnerDeactivated)
        ->and($rows[$unrelated->id]->deactivated_at)->toBeNull()
        ->and($rows[$agent->id]->deactivated_at)->not->toBeNull()
        ->and(Principal::query()->active()->pluck('id')->all())->toBe([$unrelated->id]);

    Event::assertDispatched(PrincipalDeactivated::class, fn (PrincipalDeactivated $event): bool => $event->principal->is($owner)
        && $event->reason === DeactivationReason::Offboarded
        && $event->ownedIds === [$agent->id, $subAgent->id]);
});

it('reports a principal active only when it, and every owner up the chain, is (P4, P7)', function (): void {
    migratePrincipals();
    $owner = directory()->forMember(member(), null);
    $agent = directory()->createService('agent', null, 'Agent', $owner);

    expect(directory()->isActive($agent->id))->toBeTrue()
        ->and(directory()->isActive((string) Str::uuid7()))->toBeFalse();

    DB::table('principals')->where('id', $owner->id)->update(['expires_at' => now()->subMinute()]);
    expect(directory()->isActive($agent->id))->toBeFalse()
        ->and(directory()->isActive($owner->id))->toBeFalse();

    DB::table('principals')->where('id', $owner->id)->update(['expires_at' => now()->addDay()]);
    expect(directory()->isActive($agent->id))->toBeTrue();

    DB::table('principals')->where('id', $owner->id)->update(['deactivated_at' => now()]);
    expect(directory()->isActive($agent->id))->toBeFalse();

    DB::table('principals')->where('id', $owner->id)->update(['deactivated_at' => null]);
    DB::table('users')->where('id', 1)->delete();
    expect(directory()->isActive($owner->id))->toBeFalse()
        ->and(directory()->isActive($agent->id))->toBeFalse();
})->group('engines');

it('scopes principals by organisation', function (): void {
    migratePrincipals();
    $organisationId = (string) Str::uuid();
    $member = directory()->forMember(member(), $organisationId);
    directory()->forMember(member(2), (string) Str::uuid());
    directory()->forMember(member(2), null);

    expect(Principal::query()->forOrganisation($organisationId)->pluck('id')->all())->toBe([$member->id])
        ->and(Principal::query()->forOrganisation(null)->count())->toBe(1)
        ->and($member->user->is(member()))->toBeTrue();
});

it('names a membership principal after the user, then the email, then generically', function (): void {
    migratePrincipals();
    $named = member()->forceFill(['name' => 'Ada Lovelace']);
    $unnamed = member(2)->forceFill(['email' => '']);

    expect(directory()->forMember($named, null)->name)->toBe('Ada Lovelace')
        ->and(directory()->forMember($unnamed, null)->name)->toBe('User')
        ->and(directory()->forMember(member(), (string) Str::uuid())->name)->toBe('a@example.com');
});

it('accepts an owner chain of seven and refuses one of eight with the exact message (P8)', function (): void {
    migratePrincipals();
    $chain = [directory()->createService('agent', null, 'Root')];

    foreach (range(1, 6) as $level) {
        $chain[] = directory()->createService('agent', null, "Level {$level}", $chain[array_key_last($chain)]);
    }

    $eighth = directory()->createService('agent', null, 'Eighth', $chain[6]);

    expect(fn () => directory()->createService('agent', null, 'Ninth', $eighth))
        ->toThrow(InvalidArgumentException::class, 'The owner chain is cyclic or deeper than eight principals.');

    // A ninth level written directly is inactive at use time.
    $ninthId = (string) Str::uuid7();
    DB::table('principals')->insert(['id' => $ninthId, 'type' => 'agent', 'name' => 'Ninth', 'owner_principal_id' => $eighth->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(directory()->isActive($eighth->id))->toBeTrue()
        ->and(directory()->isActive($ninthId))->toBeFalse();
});

it('keeps instance principals idempotent without the singleton index and ignores look-alike rows', function (): void {
    config()->set('access.principals.instance_types', ['instance_service']);
    migratePrincipals(false, ['instance_service']);
    $organisationId = (string) Str::uuid();
    DB::table('principals')->insert([
        ['id' => (string) Str::uuid7(), 'type' => 'instance_service', 'name' => 'Scoped', 'organisation_id' => $organisationId, 'user_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid7(), 'type' => 'instance_service', 'name' => 'With user', 'organisation_id' => null, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $instance = directory()->instance('instance_service', 'Instance');

    expect($instance->name)->toBe('Instance')
        ->and(directory()->instance('instance_service', 'Again')->id)->toBe($instance->id)
        ->and(Principal::query()->count())->toBe(3);
});

it('deactivates owned principals once even when ownership loops back', function (): void {
    migratePrincipals();
    Event::fake([PrincipalDeactivated::class]);
    $first = directory()->createService('agent', null, 'First');
    $second = directory()->createService('agent', null, 'Second', $first);
    DB::table('principals')->where('id', $first->id)->update(['owner_principal_id' => $second->id]);

    directory()->deactivate($first, DeactivationReason::Admin);

    expect(Principal::query()->findOrFail($second->id)->deactivation_reason)->toBe(DeactivationReason::OwnerDeactivated)
        ->and(Principal::query()->findOrFail($first->id)->deactivated_at)->toBeInstanceOf(CarbonInterface::class);

    Event::assertDispatched(PrincipalDeactivated::class, fn (PrincipalDeactivated $event): bool => $event->ownedIds === [$second->id]);
});

it('excludes expired principals from the active scope', function (): void {
    migratePrincipals();
    $expired = directory()->createService('service', null, 'Expired');
    $current = directory()->createService('service', null, 'Current');
    DB::table('principals')->where('id', $expired->id)->update(['expires_at' => now()->subMinute()]);
    DB::table('principals')->where('id', $current->id)->update(['expires_at' => now()->addDay()]);

    expect(Principal::query()->active()->pluck('id')->all())->toBe([$current->id]);
});

it('refuses a misconfigured principal model or users model', function (mixed $principalModel): void {
    migratePrincipals();
    config()->set('access.principals.model', $principalModel);

    expect(fn () => directory()->createService('service', null, 'Any'))->toThrow(LogicException::class, 'access.principals.model');
})->with([
    'not a principal' => [stdClass::class],
    'not a string' => [null],
]);

it('only creates types the product lists', function (): void {
    migratePrincipals();
    config()->set('access.principals.types', 'service');

    expect(fn () => directory()->createService('service', null, 'Any'))->toThrow(InvalidArgumentException::class, 'access.principals.types');

    config()->set('access.principals.types', ['user', 'service', 'agent']);
});

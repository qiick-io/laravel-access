<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\Principal;
use Qiick\Access\Principals\PrincipalCreated;
use Qiick\Access\Principals\PrincipalDeactivated;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\Access\Principals\UserDeletionGuard;
use Qiick\Access\Principals\UserHasPrincipalsException;
use Qiick\Access\Tests\Support\SpatieUser;

/*
 * PrincipalDirectory (design §15.4) on the configured engine, with the
 * security cases of §15.7 (P2–P8).
 */

// Every case runs on PostgreSQL, MySQL and MariaDB in CI.
pest()->group('engines');

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

/**
 * A users model without Spatie's HasRoles, whose deleting hook needs its tables.
 */
class ErasableUser extends User
{
    protected $table = 'users';

    public $timestamps = false;
}

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

it('returns the instance principal another worker created concurrently (P3)', function (): void {
    $defaultConnection = config('database.default');

    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database cannot be shared with a second connection.');
    }

    migratePrincipals(true, ['instance_service']);
    // A second connection to the same database plays the concurrent worker.
    config()->set('database.connections.concurrent_worker', config("database.connections.{$defaultConnection}"));
    $isRaceSimulated = false;

    // The other worker commits the singleton between our check and our insert.
    Principal::creating(function () use (&$isRaceSimulated): void {
        if ($isRaceSimulated) {
            return;
        }

        $isRaceSimulated = true;
        DB::connection('concurrent_worker')->table('principals')->insert([
            'id' => (string) Str::uuid7(), 'type' => 'instance_service', 'name' => 'Winner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $instance = directory()->instance('instance_service', 'Loser');

    expect($instance->name)->toBe('Winner')
        ->and(Principal::query()->count())->toBe(1);
})->group('engines');

/**
 * Runs $work in $workers forked processes, each on its own database
 * connection, all released at the same instant, and returns what each one
 * returned (or the exception it threw) with the microtime it started and
 * finished, so the caller can prove the runs overlapped. Real processes, not a
 * second connection in one process: checklist item 5.
 *
 * @param  Closure(int): array<string, string>  $work
 * @return list<array<string, mixed>>
 */
function raceInChildProcesses(int $workers, Closure $work): array
{
    // Unique per run: a stale file of a killed run can never pass as a result.
    $resultDirectory = sys_get_temp_dir().'/qiick-access-principal-race-'.bin2hex(random_bytes(8));
    mkdir($resultDirectory, 0777, true);
    // The children open their own connections; closing ours first means no
    // child can end the session the parent would reuse.
    DB::disconnect();
    // Far enough ahead for every fork to connect on a slow runner.
    $startAt = microtime(true) + 2.0;
    $childPids = [];

    for ($worker = 0; $worker < $workers; $worker++) {
        $childPid = pcntl_fork();

        if ($childPid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($childPid === 0) {
            try {
                app()->forgetInstance(PrincipalDirectory::class);
                DB::connection()->getPdo();

                if ($startAt > microtime(true)) {
                    time_sleep_until($startAt);
                }

                $startedAt = microtime(true);
                $result = [...$work($worker), 'started_at' => $startedAt, 'finished_at' => microtime(true)];
            } catch (Throwable $throwable) {
                $result = ['error' => $throwable::class.': '.$throwable->getMessage()];
            }

            file_put_contents("{$resultDirectory}/{$worker}", json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            // Skip PHPUnit's shutdown in the child; the parent owns the run.
            posix_kill(getmypid(), SIGKILL);
        }

        $childPids[] = $childPid;
    }

    // A child stuck on a lock must fail the test, not hang the job.
    $deadline = microtime(true) + 60;

    while ($childPids !== [] && microtime(true) < $deadline) {
        foreach ($childPids as $index => $childPid) {
            if (pcntl_waitpid($childPid, $status, WNOHANG) !== 0) {
                unset($childPids[$index]);
            }
        }

        usleep(10_000);
    }

    foreach ($childPids as $childPid) {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
    }

    $results = [];

    for ($worker = 0; $worker < $workers; $worker++) {
        $resultFile = "{$resultDirectory}/{$worker}";
        $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;
        $results[] = is_array($result) ? $result : ['error' => "worker {$worker} left no readable result"];
        @unlink($resultFile);
    }

    rmdir($resultDirectory);
    DB::reconnect();

    if ($childPids !== []) {
        throw new RuntimeException(count($childPids).' race workers did not finish within 60 seconds.');
    }

    return $results;
}

it('gives concurrent processes one instance principal and one membership principal (P1, P3)', function (bool $isOrganisationRequired, ?string $organisationId): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are required for the process race.');
    }

    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database cannot be shared between processes.');
    }

    migratePrincipals($isOrganisationRequired, $isOrganisationRequired ? ['instance_service'] : []);
    // Twenty users raced by every worker: one call each would rarely collide.
    DB::table('users')->insert(array_map(static fn (int $id): array => ['id' => $id], range(3, 20)));

    $results = raceInChildProcesses(6, static fn (int $worker): array => [
        ...($isOrganisationRequired ? ['instance' => directory()->instance('instance_service', "Worker {$worker}")->id] : []),
        'members' => implode(',', array_map(
            static fn (int $userId): string => directory()->forMember(member($userId), $organisationId)->id,
            range(1, 20),
        )),
    ]);

    expect(array_column($results, 'error'))->toBe([])
        // The runs overlapped: the last worker started before the first one
        // finished. Sequential runs would pass the other checks too.
        ->and(max(array_column($results, 'started_at')))->toBeLessThan(min(array_column($results, 'finished_at')))
        ->and(array_unique(array_column($results, 'members')))->toHaveCount(1)
        ->and(Principal::query()->whereNotNull('user_id')->count())->toBe(20);

    if ($isOrganisationRequired) {
        expect(array_unique(array_column($results, 'instance')))->toHaveCount(1)
            ->and(Principal::query()->where('type', 'instance_service')->count())->toBe(1);
    }
})->with([
    'organisations required' => [true, '0195f7a2-6a3e-7c2d-9b1a-4f2e8d6c5a10'],
    'instance-level membership' => [false, null],
])->group('engines');

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
    // A user principal whose user link is gone (erasure unlinks it) is inactive.
    DB::table('principals')->where('id', $owner->id)->update(['user_id' => null]);
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

it('refuses instance principals unless organisations are required (review B5 / N4)', function (): void {
    migratePrincipals();
    config()->set('access.principals.instance_types', ['instance_service']);

    expect(fn () => directory()->instance('instance_service', 'Instance'))->toThrow(InvalidArgumentException::class, 'organisation_required')
        ->and(Principal::query()->count())->toBe(0);
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

// Review of #16.

it('never overwrites a deactivated principal, so an erased tombstone stays erased (review B3)', function (): void {
    migratePrincipals();
    $organisationId = (string) Str::uuid();
    $principal = directory()->forMember(member(), $organisationId);
    directory()->deactivate($principal, DeactivationReason::Erased);
    $erasedAt = Principal::query()->findOrFail($principal->id)->deactivated_at;

    $this->travel(5)->minutes();
    directory()->deactivate(Principal::query()->findOrFail($principal->id), DeactivationReason::Offboarded);
    $row = Principal::query()->findOrFail($principal->id);

    expect($row->deactivation_reason)->toBe(DeactivationReason::Erased)
        ->and($row->deactivated_at->equalTo($erasedAt))->toBeTrue()
        ->and(fn () => directory()->forMember(member(), $organisationId))->toThrow(LogicException::class, 'erased')
        ->and(directory()->isActive($principal->id))->toBeFalse();
});

it('reactivates only offboarded membership principals (review N7)', function (DeactivationReason $reason, bool $isReactivated): void {
    migratePrincipals();
    $organisationId = (string) Str::uuid();
    $principal = directory()->forMember(member(), $organisationId);
    directory()->deactivate($principal, $reason);

    $returned = directory()->forMember(member(), $organisationId);

    expect($returned->id)->toBe($principal->id)
        ->and(directory()->isActive($principal->id))->toBe($isReactivated)
        ->and($returned->deactivation_reason)->toBe($isReactivated ? null : $reason);
})->with([
    'offboarded' => [DeactivationReason::Offboarded, true],
    'admin' => [DeactivationReason::Admin, false],
    'owner deactivated' => [DeactivationReason::OwnerDeactivated, false],
    'expired' => [DeactivationReason::Expired, false],
]);

it('refuses an owner from another organisation or an inactive owner (review B4)', function (): void {
    migratePrincipals(true, ['instance_service']);
    $organisationA = (string) Str::uuid();
    $organisationB = (string) Str::uuid();
    $ownerInA = directory()->forMember(member(), $organisationA);

    expect(fn () => directory()->createService('agent', $organisationB, 'Agent', $ownerInA))
        ->toThrow(InvalidArgumentException::class, 'same organisation')
        ->and(directory()->createService('agent', strtoupper($organisationA), 'Agent', $ownerInA)->organisation_id)->toBe($organisationA);

    directory()->deactivate($ownerInA, DeactivationReason::Admin);

    expect(fn () => directory()->createService('agent', $organisationA, 'Agent', $ownerInA))
        ->toThrow(InvalidArgumentException::class, 'not active');
});

it('stores organisation ids in lower case so one membership never splits (review N5)', function (): void {
    migratePrincipals();
    $organisationId = (string) Str::uuid();

    $principal = directory()->forMember(member(), strtoupper($organisationId));

    expect($principal->organisation_id)->toBe($organisationId)
        ->and(directory()->forMember(member(), $organisationId)->id)->toBe($principal->id)
        ->and(Principal::query()->count())->toBe(1);
});

it('fails closed on an instance-level row the organisation rule forbids (review B6)', function (): void {
    // A schema migrated without the rule, then the product turned it on.
    migratePrincipals();
    config()->set('access.principals.organisation_required', true);
    config()->set('access.principals.instance_types', ['instance_service']);
    $rogueId = (string) Str::uuid7();
    $strayId = (string) Str::uuid7();
    DB::table('principals')->insert([
        ['id' => $rogueId, 'type' => 'instance_service', 'user_id' => 1, 'name' => 'Rogue', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $strayId, 'type' => 'service', 'user_id' => null, 'name' => 'Stray', 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(directory()->isActive($rogueId))->toBeFalse()
        ->and(directory()->isActive($strayId))->toBeFalse()
        ->and(fn () => directory()->instance('instance_service', 'Instance'))->toThrow(LogicException::class, 'has a user');
});

it('keeps the model guarded against mass assignment (review N1)', function (): void {
    migratePrincipals();

    expect(fn () => Principal::query()->create(['type' => 'service', 'name' => 'Mass assigned']))
        ->toThrow(MassAssignmentException::class);
});

it('erases a user: principals erased and unlinked, then the user deleted, in one transaction (decision 2026-11-24)', function (): void {
    config()->set('auth.providers.users.model', ErasableUser::class);
    migratePrincipals();
    Event::fake([PrincipalDeactivated::class]);
    $organisationId = (string) Str::uuid();
    $member = directory()->forMember(member(), $organisationId);
    $instanceLevel = directory()->forMember(member(), null);
    $agent = directory()->createService('agent', $organisationId, 'Agent', $member);
    directory()->deactivate($instanceLevel, DeactivationReason::Offboarded);
    $erasedEarlier = directory()->forMember(member(), (string) Str::uuid());
    directory()->deactivate($erasedEarlier, DeactivationReason::Erased);
    $erasedEarlierAt = Principal::query()->findOrFail($erasedEarlier->id)->deactivated_at;
    $this->travel(5)->minutes();
    $other = directory()->forMember(member(2), $organisationId);

    directory()->eraseUser(ErasableUser::query()->findOrFail(1), static function (ErasableUser $user): void {
        $user->delete();
    });

    $rows = Principal::query()->get()->keyBy('id');

    expect(DB::table('users')->where('id', 1)->exists())->toBeFalse()
        ->and($rows[$member->id]->user_id)->toBeNull()
        ->and($rows[$member->id]->deactivation_reason)->toBe(DeactivationReason::Erased)
        ->and($rows[$member->id]->name)->toBe('Erased user')
        ->and($rows[$instanceLevel->id]->deactivation_reason)->toBe(DeactivationReason::Erased)
        ->and($rows[$instanceLevel->id]->user_id)->toBeNull()
        ->and($rows[$agent->id]->deactivation_reason)->toBe(DeactivationReason::OwnerDeactivated)
        ->and($rows[$erasedEarlier->id]->deactivated_at->equalTo($erasedEarlierAt))->toBeTrue()
        ->and($rows[$erasedEarlier->id]->user_id)->toBeNull()
        ->and($rows[$instanceLevel->id]->deactivated_at->greaterThan($erasedEarlierAt))->toBeTrue()
        ->and($rows[$other->id]->deactivated_at)->toBeNull()
        ->and(directory()->isActive($member->id))->toBeFalse();

    Event::assertDispatched(PrincipalDeactivated::class, fn (PrincipalDeactivated $event): bool => $event->principal->is($member)
        && $event->reason === DeactivationReason::Erased && $event->ownedIds === [$agent->id]);
})->group('engines');

it('rolls the erasure back when deleting the user fails', function (): void {
    migratePrincipals();
    $principal = directory()->forMember(member(), null);

    expect(fn () => directory()->eraseUser(member(), static function (): void {
        throw new RuntimeException('user deletion failed');
    }))->toThrow(RuntimeException::class, 'user deletion failed');

    $row = Principal::query()->findOrFail($principal->id);

    expect($row->user_id)->toBe(1)
        ->and($row->deactivated_at)->toBeNull();
})->group('engines');

it('explains that a user with principals must be erased, not deleted (decision 2026-11-24)', function (): void {
    config()->set('auth.providers.users.model', ErasableUser::class);
    migratePrincipals();
    directory()->forMember(member(), null);

    expect(fn () => ErasableUser::query()->findOrFail(1)->delete())
        ->toThrow(UserHasPrincipalsException::class, 'User [1] still has principals; delete it through PrincipalDirectory::eraseUser()')
        ->and(DB::table('users')->where('id', 1)->exists())->toBeTrue()
        ->and(ErasableUser::query()->findOrFail(2)->delete())->toBeTrue();
});

it('dispatches the deactivation event only after the surrounding transaction commits (review N8)', function (): void {
    migratePrincipals();
    $principal = directory()->forMember(member(), null);
    $dispatched = [];
    Event::listen(PrincipalDeactivated::class, static function (PrincipalDeactivated $event) use (&$dispatched): void {
        $dispatched[] = $event->principal->id;
    });

    DB::transaction(function () use ($principal, &$dispatched): void {
        directory()->deactivate($principal, DeactivationReason::Offboarded);

        expect($dispatched)->toBe([]);
    });

    expect($dispatched)->toBe([$principal->id]);
});

it('lets soft deletes, other models and principal-less users through the deletion guard', function (): void {
    config()->set('auth.providers.users.model', ErasableUser::class);
    migratePrincipals();
    directory()->forMember(member(), null);
    $guard = new UserDeletionGuard;
    $softDeletingUser = new class extends ErasableUser
    {
        use SoftDeletes;

        public function asForceDeleting(): static
        {
            $this->forceDeleting = true;

            return $this;
        }
    };
    $softDeletingUser->forceFill(['id' => 1]);

    $guard('eloquent.deleting: x', [$softDeletingUser]);
    $guard('eloquent.deleting: x', [Principal::query()->firstOrFail()]);
    $guard('eloquent.deleting: x', ['not a model']);
    $guard('eloquent.deleting: x', [ErasableUser::query()->findOrFail(2)]);
    // Another model whose key happens to equal the user's id.
    $guard('eloquent.deleting: x', [(new class extends Model {})->forceFill(['id' => 1])]);

    expect(fn () => $guard('eloquent.deleting: x', [$softDeletingUser->asForceDeleting()]))->toThrow(UserHasPrincipalsException::class);

    config()->set('auth.providers.users.model', null);
    $guard('eloquent.deleting: x', [ErasableUser::query()->findOrFail(1)]);

    Schema::dropIfExists('principals');
    config()->set('auth.providers.users.model', ErasableUser::class);
    $guard('eloquent.deleting: x', [ErasableUser::query()->findOrFail(1)]);

    expect(true)->toBeTrue();
});

// Re-review of #16.

/**
 * A soft-deleting users model: delete() keeps the row (review R1).
 */
class SoftDeletingUser extends ErasableUser
{
    use SoftDeletes;
}

it('preserves the membership tombstone when a product soft-deletes and restores a user', function (bool $wasAlreadyInactive): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->softDeletes();
    });
    config()->set('auth.providers.users.model', SoftDeletingUser::class);
    migratePrincipals();
    Event::fake([PrincipalDeactivated::class]);
    $user = SoftDeletingUser::query()->findOrFail(1);
    $principal = directory()->forMember($user, null);
    $agent = directory()->createService('agent', null, 'Agent', $principal);
    $deactivatedAt = now()->startOfSecond();
    $this->travelTo($deactivatedAt);

    if ($wasAlreadyInactive) {
        directory()->deactivate($principal, DeactivationReason::Offboarded);
        $this->travel(5)->minutes();
        directory()->deactivate($principal, DeactivationReason::Admin);

        expect($principal->deactivation_reason)->toBe(DeactivationReason::Offboarded);
    }

    DB::transaction(function () use ($user, $principal): void {
        $user->delete();
        directory()->markErased($principal);
        Event::assertNotDispatched(PrincipalDeactivated::class, fn (PrincipalDeactivated $event): bool => $event->reason === DeactivationReason::Erased);
    });

    Event::assertDispatched(PrincipalDeactivated::class, fn (PrincipalDeactivated $event): bool => $event->principal->id === $principal->id
        && $event->reason === DeactivationReason::Erased && $event->ownedIds === [$agent->id]);
    $markedAt = $principal->refresh()->updated_at;

    $this->travel(5)->minutes();
    directory()->markErased($principal);
    $user->restore();

    $storedPrincipal = $principal->refresh();

    expect($storedPrincipal->user_id)->toBe(1)
        ->and($storedPrincipal->deactivation_reason)->toBe(DeactivationReason::Erased)
        ->and($storedPrincipal->deactivated_at?->equalTo($deactivatedAt))->toBeTrue()
        ->and($storedPrincipal->updated_at?->equalTo($markedAt))->toBeTrue()
        ->and(directory()->isActive($principal->id))->toBeFalse()
        ->and(directory()->isActive($agent->id))->toBeFalse()
        ->and(fn () => directory()->forMember($user, null))->toThrow(LogicException::class, 'erased');
})->with(['active' => [false], 'offboarded' => [true]]);

it('refuses an erasure that leaves the user row in place, and rolls it back (review R1)', function (Closure $deleteUser): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->softDeletes();
    });
    config()->set('auth.providers.users.model', SoftDeletingUser::class);
    migratePrincipals();
    $organisationId = (string) Str::uuid();
    $principal = directory()->forMember(member(), $organisationId);

    expect(fn () => directory()->eraseUser(SoftDeletingUser::query()->findOrFail(1), $deleteUser))
        ->toThrow(LogicException::class, 'still exists');

    $row = Principal::query()->findOrFail($principal->id);

    expect($row->user_id)->toBe(1)
        ->and($row->deactivated_at)->toBeNull()
        ->and(DB::table('users')->where('id', 1)->value('deleted_at'))->toBeNull()
        ->and(directory()->forMember(member(), $organisationId)->id)->toBe($principal->id);
})->with([
    'soft delete' => [static fn (SoftDeletingUser $user): ?bool => $user->delete()],
    'no delete at all' => [static fn (): null => null],
]);

it('erases a soft-deleting user through a hard delete', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->softDeletes();
    });
    config()->set('auth.providers.users.model', SoftDeletingUser::class);
    migratePrincipals();
    $principal = directory()->forMember(member(), null);

    directory()->eraseUser(SoftDeletingUser::query()->findOrFail(1), static fn (SoftDeletingUser $user): ?bool => $user->forceDelete());

    expect(DB::table('users')->where('id', 1)->exists())->toBeFalse()
        ->and(Principal::query()->findOrFail($principal->id)->deactivation_reason)->toBe(DeactivationReason::Erased);
});

it('checks the owner organisation stored in the database, not the in-memory model (review N16)', function (): void {
    migratePrincipals(true);
    $organisationA = (string) Str::uuid();
    $organisationB = (string) Str::uuid();
    $owner = directory()->forMember(member(), $organisationA);
    $owner->organisation_id = $organisationB;

    expect(fn () => directory()->createService('agent', $organisationB, 'Agent', $owner))
        ->toThrow(InvalidArgumentException::class, 'same organisation');
});

it('refuses an erasure when users and principals use different connections (review R5)', function (): void {
    migratePrincipals();
    $principal = directory()->forMember(member(), null);
    config()->set('database.connections.users_connection', config('database.connections.'.config('database.default')));
    config()->set('auth.providers.users.model', UsersOnAnotherConnection::class);

    expect(fn () => directory()->eraseUser(member(), static fn (): null => null))
        ->toThrow(LogicException::class, 'one database connection')
        ->and(Principal::query()->findOrFail($principal->id)->deactivated_at)->toBeNull();
});

/**
 * A users model on a connection name other than the principals' (review R5).
 */
class UsersOnAnotherConnection extends ErasableUser
{
    protected $connection = 'users_connection';
}

it('announces a new principal only once the surrounding transaction commits (AC-c)', function (): void {
    migratePrincipals();
    $announcedPrincipalIds = [];
    Event::listen(PrincipalCreated::class, static function (PrincipalCreated $event) use (&$announcedPrincipalIds): void {
        $announcedPrincipalIds[] = $event->principal->id;
    });

    DB::beginTransaction();
    directory()->forMember(member(1), null);
    $announcedInsideTransaction = $announcedPrincipalIds;
    DB::rollBack();

    expect($announcedInsideTransaction)->toBe([])
        ->and($announcedPrincipalIds)->toBe([])
        ->and(Principal::query()->count())->toBe(0);

    $committed = DB::transaction(static fn (): Principal => directory()->forMember(member(2), null));

    expect($announcedPrincipalIds)->toBe([$committed->id]);
});

/**
 * A principal model on its own connection, as access.principals.model allows.
 */
class PrincipalOnSideConnection extends Principal
{
    protected $connection = 'principals_side';
}

/**
 * Builds the principals table (with its users table) on a separate in-memory
 * SQLite connection and points access.principals.model at it.
 */
function migratePrincipalsOnSideConnection(): void
{
    config()->set('database.connections.principals_side', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $defaultConnection = config('database.default');
    config()->set('database.default', 'principals_side');

    try {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
        });
        DB::table('users')->insert([['id' => 1, 'email' => 'a@example.com']]);
        migratePrincipals();
    } finally {
        config()->set('database.default', $defaultConnection);
    }

    config()->set('access.principals.model', PrincipalOnSideConnection::class);
    app()->forgetInstance(PrincipalDirectory::class);
}

it('fails closed on an invalid principal model and ignores a non-Eloquent users model', function (): void {
    config()->set('auth.providers.users.model', ErasableUser::class);
    migratePrincipals();
    $guard = new UserDeletionGuard;

    foreach ([stdClass::class, 42] as $invalidModel) {
        config()->set('access.principals.model', $invalidModel);

        expect(fn () => $guard('eloquent.deleting: x', [ErasableUser::query()->findOrFail(2)]))
            ->toThrow(LogicException::class, 'access.principals.model must be');
    }

    config()->set('auth.providers.users.model', stdClass::class);
    $guard('eloquent.deleting: x', [new stdClass]);
});

it('deactivates inside a transaction on the principal model connection', function (): void {
    migratePrincipalsOnSideConnection();
    $owner = directory()->forMember(member(), null);
    directory()->createService('agent', null, 'Agent', $owner);
    $transactionLevels = [];
    DB::connection('principals_side')->listen(static function (QueryExecuted $query) use (&$transactionLevels): void {
        if (str_starts_with(strtolower($query->sql), 'update')) {
            $transactionLevels[] = $query->connection->transactionLevel();
        }
    });

    directory()->deactivate($owner, DeactivationReason::Offboarded);

    expect($transactionLevels)->not->toBeEmpty()
        ->and(array_unique($transactionLevels))->toBe([1]);
});

it('refuses deleting a user who has principals on the principal model connection', function (): void {
    config()->set('auth.providers.users.model', ErasableUser::class);
    migratePrincipalsOnSideConnection();
    directory()->forMember(ErasableUser::query()->findOrFail(1), null);

    // The default connection has no principals table: only the model's counts.
    expect(fn () => ErasableUser::query()->findOrFail(1)->delete())->toThrow(UserHasPrincipalsException::class)
        ->and(DB::table('users')->where('id', 1)->exists())->toBeTrue();
});

/**
 * A principal subclass whose global scope hides every row.
 */
class PrincipalHidingEveryRow extends Principal
{
    protected static function booted(): void
    {
        static::addGlobalScope('hide_every_row', static fn ($query) => $query->whereRaw('1 = 0'));
    }
}

it('counts principals hidden by a global scope of the configured model', function (): void {
    config()->set('auth.providers.users.model', ErasableUser::class);
    migratePrincipals();
    DB::table('principals')->insert(['id' => (string) Str::uuid7(), 'type' => 'user', 'user_id' => 1, 'name' => 'Member']);
    config()->set('access.principals.model', PrincipalHidingEveryRow::class);

    expect(fn () => (new UserDeletionGuard)('eloquent.deleting: x', [ErasableUser::query()->findOrFail(1)]))
        ->toThrow(UserHasPrincipalsException::class);
});

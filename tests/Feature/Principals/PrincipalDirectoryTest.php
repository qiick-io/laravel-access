<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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

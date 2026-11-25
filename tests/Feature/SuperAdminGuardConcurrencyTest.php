<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\SuperAdminGuard;
use Spatie\Permission\PermissionRegistrar;

/**
 * Minimal Eloquent user for the concurrent demotion race (B1). Roles live in a
 * side table so demotion is a real DELETE visible across connections.
 */
final class GuardRaceUser extends Model implements Authenticatable
{
    protected $table = 'guard_race_users';

    public $timestamps = false;

    protected $guarded = [];

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
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

    /**
     * @return list<string>
     */
    public function getRoleNames(): array
    {
        return $this->roles()->pluck('name')->filter()->values()->all();
    }

    /**
     * @return HasMany<GuardRaceRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(GuardRaceRole::class, 'user_id');
    }
}

final class GuardRaceRole extends Model
{
    protected $table = 'guard_race_roles';

    public $timestamps = false;

    protected $guarded = [];
}

final class GuardRaceActiveUsers implements ActiveUsers
{
    public function query(): Builder
    {
        return GuardRaceUser::query()->where('is_active', true);
    }
}

function createGuardRaceSchema(): void
{
    Schema::dropIfExists('guard_race_roles');
    Schema::dropIfExists('guard_race_users');

    Schema::create('guard_race_users', function (Blueprint $table): void {
        $table->increments('id');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('guard_race_roles', function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->string('name');
        $table->foreign('user_id')->references('id')->on('guard_race_users')->cascadeOnDelete();
    });
}

function seedTwoSuperAdmins(): array
{
    $first = GuardRaceUser::query()->create(['is_active' => true]);
    $second = GuardRaceUser::query()->create(['is_active' => true]);
    GuardRaceRole::query()->create(['user_id' => $first->id, 'name' => 'super-admin']);
    GuardRaceRole::query()->create(['user_id' => $second->id, 'name' => 'super-admin']);

    return [$first->id, $second->id];
}

function makeConcurrencyGuard(): SuperAdminGuard
{
    $roleCatalog = Mockery::mock(RoleCatalog::class);
    $roleCatalog->shouldReceive('superAdminRoleName')->andReturn('super-admin');

    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);

    $vocabulary = Mockery::mock(PermissionVocabulary::class);
    $vocabulary->shouldReceive('all')->andReturn([]);
    $vocabulary->shouldReceive('isPermission')->andReturn(false);

    $teamContext = Mockery::mock(TeamContext::class);
    $teamContext->shouldReceive('currentId')->andReturn(null);

    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->teams = false;

    $resolver = new EffectivePermissionResolver(
        $roleCatalog,
        $groupRoles,
        $vocabulary,
        $teamContext,
        $registrar,
    );

    return new SuperAdminGuard($resolver, new GuardRaceActiveUsers, app('db'));
}

function demoteGuardRaceUser(int $userId): void
{
    GuardRaceRole::query()->where('user_id', $userId)->where('name', 'super-admin')->delete();
}

function countGuardRaceSuperAdmins(): int
{
    return (int) GuardRaceRole::query()->where('name', 'super-admin')->count();
}

/**
 * B1: two concurrent demotions of different super-admins must not both commit
 * when that would leave zero. Requires real row locks (MySQL / PostgreSQL).
 */
it('serializes concurrent demotions so at least one active super-admin remains (B1)', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('pcntl is required for the concurrent demotion race');
    }

    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('B1 race needs MySQL or PostgreSQL row locks');
    }

    createGuardRaceSchema();
    [$firstId, $secondId] = seedTwoSuperAdmins();
    expect(countGuardRaceSuperAdmins())->toBe(2);

    // Mid-barrier inside $change(): both TXs finish the pre-count, then both
    // demote — the classic write-skew window B1 describes.
    $readyDir = sys_get_temp_dir().'/qiick-access-sa-ready-'.getmypid();
    @mkdir($readyDir, 0777, true);
    $goPath = $readyDir.'/go';
    @unlink($goPath);

    $waitForSiblingThenDemote = static function (int $userId, string $selfToken) use ($readyDir, $goPath): void {
        file_put_contents($readyDir.'/'.$selfToken, 'ready');
        // Brief sync window: long enough for two unlocked TXs to both pass the
        // pre-count, short enough that a FOR UPDATE holder is not stuck waiting
        // on a sibling that cannot enter until the lock is released.
        $deadline = microtime(true) + 0.25;

        while (microtime(true) < $deadline) {
            $readyCount = (is_file($readyDir.'/child') ? 1 : 0) + (is_file($readyDir.'/parent') ? 1 : 0);

            if ($readyCount >= 2) {
                file_put_contents($goPath, 'go');
                break;
            }

            usleep(5_000);
        }

        $deadline = microtime(true) + 0.25;

        while (! is_file($goPath) && microtime(true) < $deadline) {
            usleep(5_000);
        }

        demoteGuardRaceUser($userId);
    };

    $childPid = pcntl_fork();

    if ($childPid === -1) {
        $this->fail('pcntl_fork failed');
    }

    if ($childPid === 0) {
        try {
            DB::purge(DB::getDefaultConnection());
            DB::reconnect();
            $guard = makeConcurrencyGuard();
            $rolledBack = $guard->wouldLeaveNoActiveSuperAdmin(
                static fn () => $waitForSiblingThenDemote($firstId, 'child'),
            );
            exit($rolledBack ? 10 : 20);
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage().PHP_EOL);
            exit(30);
        }
    }

    DB::purge(DB::getDefaultConnection());
    DB::reconnect();
    $guard = makeConcurrencyGuard();
    $parentRolledBack = $guard->wouldLeaveNoActiveSuperAdmin(
        static fn () => $waitForSiblingThenDemote($secondId, 'parent'),
    );

    $status = 0;
    pcntl_waitpid($childPid, $status);
    array_map('unlink', glob($readyDir.'/*') ?: []);
    @rmdir($readyDir);

    $childCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 30;

    expect($childCode)->not->toBe(30)
        ->and(countGuardRaceSuperAdmins())->toBeGreaterThanOrEqual(1)
        ->and($parentRolledBack || $childCode === 10)->toBeTrue();

    Schema::dropIfExists('guard_race_roles');
    Schema::dropIfExists('guard_race_users');
})->group('engines');

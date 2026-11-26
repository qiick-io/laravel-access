<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Access\Erasure\AccessEraser;
use Qiick\Access\Tests\Support\SpatieUser;
use Qiick\Standards\Erasure\Support\ErasureFixture;
use Qiick\Standards\Erasure\Support\ErasurePerson;

/**
 * Classifies Access and Spatie assignment tables. `principals.id` remains as
 * an audit and credentials foreign key. Access has no subject data in Laravel
 * cache stores, files or other connections. Its process-local resolver and
 * super-admin verdicts are exercised in AccessErasureTest.
 */
return new class implements ErasureFixture
{
    public function packageName(): string
    {
        return 'qiick/laravel-access';
    }

    public function eraserClass(): string
    {
        return AccessEraser::class;
    }

    public function connectionName(): ?string
    {
        return null;
    }

    public function tables(): array
    {
        return [
            'principals' => [
                ...array_fill_keys(['type', 'organisation_id', 'expires_at', 'deactivated_at', 'deactivation_reason', 'created_at', 'updated_at', 'organisation_key', 'instance_type'], 'other'),
                ...array_fill_keys(['user_id', 'owner_principal_id', 'name', 'created_by', 'metadata'], 'personal'),
                'id' => 'retained',
            ],
            'user_groups' => array_fill_keys(['id', 'public_id', 'name', 'description', 'metadata', 'organisation_id', 'is_instance', 'created_at', 'updated_at', 'deleted_at', 'organisation_key'], 'other'),
            'user_group_user' => [
                ...array_fill_keys(['user_group_id', 'expires_at', 'created_at', 'updated_at'], 'other'),
                'user_id' => 'personal',
            ],
            'user_group_role' => array_fill_keys(['user_group_id', 'role_id', 'created_at', 'updated_at'], 'other'),
            'roles' => array_fill_keys(['id', 'organisation_id', 'name', 'guard_name', 'created_at', 'updated_at'], 'other'),
            'permissions' => array_fill_keys(['id', 'name', 'guard_name', 'created_at', 'updated_at'], 'other'),
            'role_has_permissions' => array_fill_keys(['role_id', 'permission_id'], 'other'),
            'model_has_roles' => [
                ...array_fill_keys(['role_id', 'model_type', 'organisation_id'], 'other'),
                'model_id' => 'personal',
            ],
            'model_has_permissions' => [
                ...array_fill_keys(['permission_id', 'model_type', 'organisation_id'], 'other'),
                'model_id' => 'personal',
            ],
        ];
    }

    public function retainedCleartextColumns(): array
    {
        return ['principals.id' => 'legal_obligation'];
    }

    public function diskPaths(ErasurePerson $person): array
    {
        return [];
    }

    public function additionalConnections(): array
    {
        return [];
    }

    public function makePerson(): ErasurePerson
    {
        $userId = 100000000 + DB::table('users')->count();
        $person = ErasurePerson::fresh((string) $userId, principalCount: 2);
        DB::table('users')->insert(['id' => $userId, 'email' => $person->identifiers[0]]);

        return $person;
    }

    public function seed(ErasurePerson $person, ?string $organisationId): void
    {
        $organisationId ??= (string) Str::uuid();
        $principalIndex = DB::table('principals')->where('user_id', $person->userId)->count();
        $principalId = $person->publicIds[$principalIndex];
        DB::table('principals')->insert([
            'id' => $principalId,
            'type' => 'user',
            'user_id' => $person->userId,
            'organisation_id' => $organisationId,
            'created_by' => $person->userId,
            'name' => $person->identifiers[0],
            'metadata' => json_encode(['notes' => $person->identifiers[1]], JSON_THROW_ON_ERROR),
        ]);
        DB::table('principals')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'agent',
            'organisation_id' => $organisationId,
            'owner_principal_id' => $principalId,
            'created_by' => $person->userId,
            'name' => $person->identifiers[2],
            'metadata' => json_encode(['notes' => $person->otherPersonalValues[1]], JSON_THROW_ON_ERROR),
        ]);

        $groupId = DB::table('user_groups')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Members '.Str::uuid(),
            'organisation_id' => $organisationId,
        ]);
        DB::table('user_group_user')->insert(['user_group_id' => $groupId, 'user_id' => $person->userId]);

        $roleId = DB::table('roles')->insertGetId(['organisation_id' => $organisationId, 'name' => 'Member '.Str::uuid(), 'guard_name' => 'web']);
        $permissionId = DB::table('permissions')->insertGetId(['name' => (string) Str::uuid(), 'guard_name' => 'web']);
        DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        DB::table('user_group_role')->insert(['user_group_id' => $groupId, 'role_id' => $roleId]);

        $assignment = ['organisation_id' => $organisationId, 'model_id' => $person->userId, 'model_type' => (new SpatieUser)->getMorphClass()];
        DB::table('model_has_roles')->insert([...$assignment, 'role_id' => $roleId]);
        DB::table('model_has_permissions')->insert([...$assignment, 'permission_id' => $permissionId]);
    }

    public function supportsOrganisationScope(): bool
    {
        return true;
    }

    public function organisationScopedTables(): array
    {
        return ['principals', 'user_group_user', 'model_has_roles', 'model_has_permissions'];
    }

    public function cacheKeys(ErasurePerson $person): array
    {
        return [];
    }

    public function call(Closure $erase): object
    {
        // Recreate the product transaction boundary without deleting its user
        // row in probes that check retries, dry runs and organisation offboarding.
        return DB::transaction($erase);
    }
};

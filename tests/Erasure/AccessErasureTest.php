<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Erasure\AccessEraser;
use Qiick\Access\Events\SubjectErasedEvent;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\Principal;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\Access\Tests\Support\SpatieUser;
use Spatie\Permission\PermissionRegistrar;

it('erases only the selected organisation and keeps the product user row', function (): void {
    $user = SpatieUser::query()->create(['email' => 'subject@example.test']);
    $organisationId = (string) Str::uuid();
    $directory = app(PrincipalDirectory::class);
    $member = $directory->forMember($user, $organisationId);
    $otherMember = $directory->forMember($user, (string) Str::uuid());
    $directory->deactivate($member, DeactivationReason::Offboarded);
    $offboardedAt = $member->refresh()->deactivated_at;
    $this->travel(30)->days();

    app(AccessEraser::class)->erase((string) $user->getKey(), (string) Str::uuid(), 'anonymise', [$member->id], organisationId: $organisationId);

    expect(Principal::query()->findOrFail($member->id)->user_id)->toBeNull()
        ->and($member->refresh()->deactivated_at->equalTo($offboardedAt))->toBeTrue()
        ->and(Principal::query()->findOrFail($otherMember->id)->user_id)->toBe($user->getKey())
        ->and(DB::table('users')->where('id', $user->getKey())->exists())->toBeTrue();
});

it('preserves instance super-admin grants when erasing one organisation', function (): void {
    $organisationId = (string) Str::uuid();
    $directMember = SpatieUser::query()->create(['email' => 'direct@example.test']);
    $groupMember = SpatieUser::query()->create(['email' => 'group@example.test']);
    $superAdminRoleId = DB::table('roles')->insertGetId(['name' => 'super-admin', 'guard_name' => 'web']);
    $memberRoleId = DB::table('roles')->insertGetId(['name' => 'member', 'guard_name' => 'web']);
    $assignment = ['model_type' => $directMember->getMorphClass(), 'model_id' => $directMember->getKey(), 'organisation_id' => $organisationId];
    DB::table('model_has_roles')->insert([
        [...$assignment, 'role_id' => $superAdminRoleId],
        [...$assignment, 'role_id' => $memberRoleId],
    ]);

    $instanceGroupId = DB::table('user_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Instance admins', 'is_instance' => true]);
    $organisationGroupId = DB::table('user_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Members', 'organisation_id' => $organisationId, 'is_instance' => false]);
    DB::table('user_group_role')->insert(['user_group_id' => $instanceGroupId, 'role_id' => $superAdminRoleId]);
    DB::table('user_group_user')->insert([
        ['user_group_id' => $instanceGroupId, 'user_id' => $groupMember->getKey()],
        ['user_group_id' => $organisationGroupId, 'user_id' => $groupMember->getKey()],
    ]);

    $resolver = app(EffectivePermissionResolver::class);
    expect($resolver->isSuperAdmin($directMember))->toBeTrue()
        ->and($resolver->isSuperAdmin($groupMember))->toBeTrue();

    $eraser = app(AccessEraser::class);
    $eraser->erase((string) $directMember->getKey(), (string) Str::uuid(), AccessEraser::DELETE, organisationId: $organisationId);
    $eraser->erase((string) $groupMember->getKey(), (string) Str::uuid(), AccessEraser::ANONYMISE, organisationId: $organisationId);

    expect(DB::table('model_has_roles')->pluck('role_id')->all())->toEqual([$superAdminRoleId])
        ->and(DB::table('user_group_user')->pluck('user_group_id')->all())->toEqual([$instanceGroupId])
        ->and($resolver->isSuperAdmin($directMember))->toBeTrue()
        ->and($resolver->isSuperAdmin($groupMember))->toBeTrue();

    $eraser->erase((string) $directMember->getKey(), (string) Str::uuid(), AccessEraser::DELETE);
    $eraser->erase((string) $groupMember->getKey(), (string) Str::uuid(), AccessEraser::ANONYMISE);

    expect($resolver->isSuperAdmin($directMember))->toBeFalse()
        ->and($resolver->isSuperAdmin($groupMember))->toBeFalse();
});

it('evicts the resolver verdicts on commit even when the caller still holds a loaded user model', function (): void {
    config()->set('permission.teams', false);
    app(PermissionRegistrar::class)->teams = false;
    $user = SpatieUser::query()->create(['email' => 'subject@example.test']);
    $otherUser = SpatieUser::query()->create(['email' => 'other@example.test']);
    $organisationId = (string) Str::uuid();
    $roleId = DB::table('roles')->insertGetId(['name' => 'super-admin', 'guard_name' => 'web']);
    $permissionId = DB::table('permissions')->insertGetId(['name' => 'can-show-users', 'guard_name' => 'web']);

    foreach ([$user, $otherUser] as $member) {
        $assignment = ['model_type' => $member->getMorphClass(), 'model_id' => $member->getKey(), 'organisation_id' => $organisationId];
        DB::table('model_has_roles')->insert([...$assignment, 'role_id' => $roleId]);
        DB::table('model_has_permissions')->insert([...$assignment, 'permission_id' => $permissionId]);
    }

    $resolver = app(EffectivePermissionResolver::class);
    $eraser = app(AccessEraser::class);
    $userId = (string) $user->getKey();
    $userPublicId = (string) Str::uuid();

    $scopedReport = $eraser->erase($userId, $userPublicId, AccessEraser::DELETE, organisationId: $organisationId);
    $outsideScope = ['deleted' => 0, 'anonymised' => 0, 'kept' => 1, 'reason' => 'outside_organisation_scope'];
    expect($scopedReport->stores['model_has_roles'])->toBe($outsideScope)
        ->and($scopedReport->stores['model_has_permissions'])->toBe($outsideScope);

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and($resolver->permissionsFor($user))->toContain('can-show-users')
        ->and($resolver->isSuperAdmin($otherUser))->toBeTrue();

    DB::beginTransaction();

    try {
        $eraser->erase($userId, $userPublicId, AccessEraser::DELETE);
        expect($resolver->isSuperAdmin($user))->toBeTrue();
    } finally {
        DB::rollBack();
    }

    expect($resolver->isSuperAdmin($user))->toBeTrue();

    $preview = $eraser->erase($userId, $userPublicId, AccessEraser::DELETE, dryRun: true);
    expect($preview->stores['cache:resolver']['deleted'])->toBe(2)
        ->and($resolver->cachedVerdictCount($user))->toBe(2);
    $report = $eraser->erase($userId, $userPublicId, AccessEraser::DELETE);

    expect($report->stores)->toBe($preview->stores)
        ->and($resolver->cachedVerdictCount($user))->toBe(0)
        ->and($resolver->cachedVerdictCount($otherUser))->toBe(1)
        ->and($resolver->isSuperAdmin($user))->toBeFalse()
        ->and($resolver->permissionsFor($user))->toBe([])
        ->and($resolver->isSuperAdmin($otherUser))->toBeTrue();
});

it('can commit the fleet erasure and the product user deletion together', function (): void {
    $user = SpatieUser::query()->create(['email' => 'subject@example.test']);
    $principal = app(PrincipalDirectory::class)->forMember($user, (string) Str::uuid());
    $hasDispatched = false;
    Event::listen(SubjectErasedEvent::class, function () use ($user, &$hasDispatched): void {
        $hasDispatched = true;
        expect(SpatieUser::query()->find($user->getKey()))->toBeNull();
    });

    DB::transaction(function () use ($user, $principal, &$hasDispatched): void {
        app(AccessEraser::class)->erase((string) $user->getKey(), (string) Str::uuid(), AccessEraser::DELETE, [$principal->id]);
        $user->delete();
        expect($hasDispatched)->toBeFalse();
    });

    expect($hasDispatched)->toBeTrue();
});

it('scrubs a snapshotted principal and a separate creator reference', function (): void {
    $user = SpatieUser::query()->create(['email' => 'subject@example.test']);
    $organisationId = (string) Str::uuid();
    $directory = app(PrincipalDirectory::class);
    $member = $directory->forMember($user, $organisationId);
    $snapshotted = $directory->createService('agent', $organisationId, 'Subject agent', $member, metadata: ['note' => 'Subject agent']);
    $owned = $directory->createService('agent', $organisationId, 'Inactive agent', $member);
    $directory->deactivate($owned, DeactivationReason::Admin);
    $deactivatedAt = $owned->refresh()->deactivated_at;
    $this->travel(1)->minutes();
    $shared = $directory->createService('service', $organisationId, 'Shared service', null, $user);

    $report = app(AccessEraser::class)->erase((string) $user->getKey(), (string) Str::uuid(), AccessEraser::DELETE, [$snapshotted->id]);

    expect($report->stores['principals']['anonymised'])->toBe(4)
        ->and($snapshotted->refresh()->metadata)->toBeNull()
        ->and($snapshotted->deactivation_reason)->toBe(DeactivationReason::Erased)
        ->and($directory->isActive($snapshotted->id))->toBeFalse()
        ->and($owned->refresh()->deactivation_reason)->toBe(DeactivationReason::Admin)
        ->and($owned->deactivated_at->equalTo($deactivatedAt))->toBeTrue()
        ->and($owned->owner_principal_id)->toBeNull()
        ->and($shared->refresh()->created_by)->toBeNull()
        ->and($shared->name)->toBe('Shared service')
        ->and($directory->isActive($shared->id))->toBeTrue();
});

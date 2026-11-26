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

    app(AccessEraser::class)->erase((string) $user->getKey(), (string) Str::uuid(), 'anonymise', [$member->id], organisationId: $organisationId);

    expect(Principal::query()->findOrFail($member->id)->user_id)->toBeNull()
        ->and(Principal::query()->findOrFail($otherMember->id)->user_id)->toBe($user->getKey())
        ->and(DB::table('users')->where('id', $user->getKey())->exists())->toBeTrue();
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

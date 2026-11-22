<?php

declare(strict_types=1);

use Qiick\Access\Laravel\SpatiePermissionVocabulary;
use Qiick\Access\Tests\Support\FakeIntPermissionEnum;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\FakeUnitPermissionEnum;

it('rejects an empty class name', function (): void {
    expect(fn () => new SpatiePermissionVocabulary(''))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a missing or non-backed-enum class', function (): void {
    expect(fn () => new SpatiePermissionVocabulary(stdClass::class))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new SpatiePermissionVocabulary(FakeUnitPermissionEnum::class))
        ->toThrow(InvalidArgumentException::class);
});

it('recognises permission names from the configured enum', function (): void {
    $vocabulary = new SpatiePermissionVocabulary(FakePermissionEnum::class);

    expect($vocabulary->isPermission('can-show-users'))->toBeTrue()
        ->and($vocabulary->isPermission('can-edit-users'))->toBeTrue()
        ->and($vocabulary->isPermission('can-delete-users'))->toBeFalse()
        ->and($vocabulary->isPermission(''))->toBeFalse();
});

it('lists every permission name for a super-admin ceiling', function (): void {
    $vocabulary = new SpatiePermissionVocabulary(FakePermissionEnum::class);

    expect($vocabulary->all())->toBe([
        'can-show-users',
        'can-edit-users',
    ]);
});

it('stringifies int-backed enum values for isPermission and all', function (): void {
    $vocabulary = new SpatiePermissionVocabulary(FakeIntPermissionEnum::class);

    expect($vocabulary->isPermission('10'))->toBeTrue()
        ->and($vocabulary->isPermission('20'))->toBeTrue()
        ->and($vocabulary->isPermission('10'))->not->toBe($vocabulary->isPermission(''))
        ->and($vocabulary->all())->toBe(['10', '20']);
});

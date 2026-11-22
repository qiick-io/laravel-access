<?php

declare(strict_types=1);

use Qiick\Access\Contracts\PermissionVocabulary;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\Laravel\NoTeamContext;
use Qiick\Access\Laravel\SpatiePermissionVocabulary;
use Qiick\Access\Tests\Support\FakePermissionEnum;

beforeEach(function (): void {
    app()->forgetInstance(PermissionVocabulary::class);
});

it('binds NoTeamContext as the default TeamContext', function (): void {
    expect(app(TeamContext::class))->toBeInstanceOf(NoTeamContext::class)
        ->and(app(TeamContext::class)->currentId())->toBeNull();
});

it('binds SpatiePermissionVocabulary when access.permission_enum is set', function (): void {
    config()->set('access.permission_enum', FakePermissionEnum::class);

    $vocabulary = app(PermissionVocabulary::class);

    expect($vocabulary)->toBeInstanceOf(SpatiePermissionVocabulary::class)
        ->and($vocabulary->isPermission('can-show-users'))->toBeTrue();
});

it('fails closed when PermissionVocabulary is resolved without a configured enum', function (): void {
    config()->set('access.permission_enum', null);

    expect(fn () => app(PermissionVocabulary::class))
        ->toThrow(RuntimeException::class);
});

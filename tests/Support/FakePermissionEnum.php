<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

/**
 * String-backed PermissionEnum stand-in for SpatiePermissionVocabulary tests.
 */
enum FakePermissionEnum: string
{
    case CanShowUsers = 'can-show-users';
    case CanEditUsers = 'can-edit-users';
}

/**
 * Int-backed enum so (string) casts in SpatiePermissionVocabulary are not equivalent.
 */
enum FakeIntPermissionEnum: int
{
    case CanShowReports = 10;
    case CanEditReports = 20;
}

/**
 * Pure unit enum (not backed) — must be rejected by SpatiePermissionVocabulary.
 */
enum FakeUnitPermissionEnum
{
    case Only;
}

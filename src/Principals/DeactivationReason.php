<?php

declare(strict_types=1);

namespace Qiick\Access\Principals;

/**
 * Why a principal was deactivated (design §15.2). `Erased` is a GDPR
 * tombstone: such a principal is never reactivated.
 *
 * @api
 */
enum DeactivationReason: string
{
    case Offboarded = 'offboarded';
    case Erased = 'erased';
    case Expired = 'expired';
    case Admin = 'admin';
    case OwnerDeactivated = 'owner_deactivated';
}

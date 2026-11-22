<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * How the current request or job was authenticated.
 *
 * @api
 */
// @pest-arch-ignore-next-line — design §2.2: enum under Contracts/, not an *Contract interface
// Pest's toBeInterfaces line-finder matches the word "class"; this comment anchors it.
enum ActorKind: string
{
    case WebSession = 'web_session';
    case ApiKey = 'api_key';
    case OAuthGrant = 'oauth_grant';
    case PublicApi = 'public_api';
    case Unattended = 'unattended';
}

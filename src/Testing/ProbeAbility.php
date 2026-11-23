<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * The AC2 probe ability (design §7): a Gate ability defined as `false`, with
 * no Policy and no permission name. Only GateBefore's super-admin grant can
 * make it pass, so a conformance test proves that a super-admin web session
 * passes it and the same user behind a key, an OAuth grant or the public door
 * does not. Test-only: registering it outside a unit-test run throws, so it
 * never reaches production code.
 *
 * @api
 */
final class ProbeAbility
{
    public const NAME = 'qiick-access-probe';

    /**
     * @return string the ability name to check with Gate::allows()
     *
     * @throws LogicException when the application is not running unit tests
     */
    public static function register(Application $app): string
    {
        if (! $app->runningUnitTests()) {
            throw new LogicException('The qiick/laravel-access probe ability is test-only (design §7).');
        }

        $app->make(Gate::class)->define(self::NAME, static fn (): bool => false);

        return self::NAME;
    }
}

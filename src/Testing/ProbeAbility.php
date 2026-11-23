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
 * does not. Test-only: registering it needs both a unit-test run and an
 * explicit opt-in from the test suite (enable()), so APP_ENV=testing alone,
 * for example on a misconfigured production host, never activates it. The
 * opt-in is a config key the shipped config/access.php does not define, so no
 * environment variable can set it.
 *
 * @api
 */
final class ProbeAbility
{
    public const NAME = 'qiick-access-probe';

    private const OPT_IN_CONFIG_KEY = 'access.testing.probe_ability';

    /**
     * Opt in from the test suite (a TestCase setUp or Pest beforeEach).
     */
    public static function enable(): void
    {
        config([self::OPT_IN_CONFIG_KEY => true]);
    }

    /**
     * @return string the ability name to check with Gate::allows()
     *
     * @throws LogicException outside a unit-test run, or without enable()
     */
    public static function register(Application $app): string
    {
        if (! $app->runningUnitTests()) {
            throw new LogicException('The qiick/laravel-access probe ability is test-only (design §7).');
        }

        if ($app->make('config')->get(self::OPT_IN_CONFIG_KEY) !== true) {
            throw new LogicException('Call ProbeAbility::enable() in the test suite before registering the probe ability.');
        }

        $app->make(Gate::class)->define(self::NAME, static fn (): bool => false);

        return self::NAME;
    }
}

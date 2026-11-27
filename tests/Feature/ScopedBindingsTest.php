<?php

declare(strict_types=1);

use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\TeamContext;
use Qiick\Access\Http\EnsureTwoFactorIsEnabled;

/*
 * Issue #66: a binding that holds a scoped dependency (the actor resolver,
 * a product's scoped TeamContext or policy) is scoped itself, so a long-lived
 * worker never carries one scope's dependency into the next.
 */

it('builds the 2FA middleware and the group source from the current scope (#66)', function (): void {
    $teamIds = ['first-organisation', 'second-organisation'];
    app()->scoped(TeamContext::class, function () use (&$teamIds): TeamContext {
        $teamId = array_shift($teamIds);

        return new class($teamId) implements TeamContext
        {
            public function __construct(private readonly string $teamId) {}

            public function currentId(): string
            {
                return $this->teamId;
            }

            public function switchTo(string|int|null $id): void {}
        };
    });

    $firstMiddleware = app(EnsureTwoFactorIsEnabled::class);
    $firstGroupSource = app(GroupRoleSource::class);
    app()->forgetScopedInstances();

    expect(app(EnsureTwoFactorIsEnabled::class))->not->toBe($firstMiddleware)
        ->and(app(GroupRoleSource::class))->not->toBe($firstGroupSource);
});

<?php

declare(strict_types=1);

use Qiick\Access\Laravel\NoTeamContext;

it('always reports a null team id', function (): void {
    $context = new NoTeamContext;

    expect($context->currentId())->toBeNull();
});

it('ignores switchTo so products can call it uniformly when teams are off', function (): void {
    $context = new NoTeamContext;
    $context->switchTo(42);
    $context->switchTo('org-1');
    $context->switchTo(null);

    expect($context->currentId())->toBeNull();
});

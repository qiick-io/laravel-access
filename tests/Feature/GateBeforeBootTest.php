<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Qiick\Access\GateBefore;

it('registers exactly one Gate::before callback that is GateBefore (AC1)', function (): void {
    $gate = app(Gate::class);
    $beforeCallbacks = (new ReflectionClass($gate))
        ->getProperty('beforeCallbacks')
        ->getValue($gate);

    expect($beforeCallbacks)->toHaveCount(1);

    $callback = $beforeCallbacks[0];
    expect($callback)->toBeInstanceOf(Closure::class);

    $closureThis = (new ReflectionFunction($callback))->getClosureThis();
    expect($closureThis)->toBeInstanceOf(GateBefore::class);
});

it('boots when register_permission_check_method is false', function (): void {
    expect(config('permission.register_permission_check_method'))->toBeFalse()
        ->and(app()->bound(GateBefore::class))->toBeTrue();
});

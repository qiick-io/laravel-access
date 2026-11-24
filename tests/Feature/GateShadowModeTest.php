<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\Contracts\LegacyGateBefore;
use Qiick\Access\GateBefore;
use Qiick\Access\Laravel\AccessBootChecks;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Laravel\AccessServiceProvider;
use Qiick\Access\Laravel\GateBeforeSelector;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\PermissionedFakeUser;

/*
 * Design §9.2 step 6 and §9.4, for externa only and removed in v2:
 * access.use_package_gate chooses which single Gate::before decides, and
 * access.gate_shadow runs the other one too and logs every disagreement
 * (ability, actor kind, credential UUID, both decisions; no secrets).
 */

/**
 * The product's legacy callback, with a fixed answer.
 */
final class FixedLegacyGateBefore implements LegacyGateBefore
{
    public int $calls = 0;

    /** @var array<int|string, mixed> */
    public array $receivedArguments = [];

    public function __construct(private readonly ?bool $decision, private readonly ?Throwable $failure = null) {}

    public function decide(?Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        $this->calls++;
        $this->receivedArguments = $arguments;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->decision;
    }
}

final class ShadowPost {}

final class ShadowPostPolicy
{
    public function update(?Authenticatable $user, ShadowPost $post): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    $groupRoles = Mockery::mock(GroupRoleSource::class);
    $groupRoles->shouldReceive('instanceRoleIdsFor')->andReturn([]);
    $groupRoles->shouldReceive('roleIdsFor')->andReturn([]);
    $this->app->instance(GroupRoleSource::class, $groupRoles);
    $this->app->forgetScopedInstances();
});

afterEach(function (): void {
    config()->set('access.use_package_gate', true);
    config()->set('access.gate_shadow', false);
    AccessContext::clear();
});

function gateCallback(): Closure
{
    return app(GateBeforeSelector::class)->callback();
}

it('decides with GateBefore by default, alone and without the legacy callback', function (): void {
    $legacy = new FixedLegacyGateBefore(false);
    app()->instance(LegacyGateBefore::class, $legacy);
    $superAdmin = new PermissionedFakeUser(1, ['super-admin']);

    expect(gateCallback()($superAdmin, 'any-ability'))->toBeTrue()
        ->and($legacy->calls)->toBe(0)
        ->and((new ReflectionFunction(gateCallback()))->getClosureThis())->toBeInstanceOf(GateBefore::class);
});

it('decides with the legacy callback when the package gate is off', function (): void {
    config()->set('access.use_package_gate', false);
    $legacy = new FixedLegacyGateBefore(null);
    app()->instance(LegacyGateBefore::class, $legacy);

    expect(gateCallback()(new PermissionedFakeUser(1, ['super-admin']), 'any-ability'))->toBeNull()
        ->and($legacy->calls)->toBe(1);
});

it('logs a disagreement in shadow mode and keeps the deciding answer', function (bool $isPackageGate, ?bool $expected): void {
    config()->set('access.use_package_gate', $isPackageGate);
    config()->set('access.gate_shadow', true);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(null));
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $superAdmin = new PermissionedFakeUser(1, ['super-admin'])));
    Log::spy();

    expect(gateCallback()($superAdmin, FakePermissionEnum::CanShowUsers->value))->toBe($expected);

    Log::shouldHaveReceived('warning')->once()->with('access.gate_shadow_mismatch', [
        'ability' => FakePermissionEnum::CanShowUsers->value,
        'actor_kind' => 'web_session',
        'credential_uuid' => null,
        'argument_types' => [],
        'package_decision' => true,
        'legacy_decision' => null,
    ]);
})->with([
    'package decides' => [true, true],
    'legacy decides' => [false, null],
]);

it('logs nothing when both callbacks agree, and names the API credential when they do not', function (): void {
    config()->set('access.gate_shadow', true);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(null));
    $member = new PermissionedFakeUser(2, ['member']);
    Log::spy();

    expect(gateCallback()($member, 'any-ability'))->toBeNull();
    Log::shouldNotHaveReceived('warning');

    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(true));
    AccessContext::enter(new ActorContext(ActorKind::ApiKey, $member, 'key-uuid'));

    expect(gateCallback()($member, 'any-ability', [new stdClass]))->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with('access.gate_shadow_mismatch', Mockery::on(
        static fn (array $context): bool => $context['actor_kind'] === 'api_key'
            && $context['credential_uuid'] === 'key-uuid'
            && $context['argument_types'] === [stdClass::class]
            && $context['legacy_decision'] === true,
    ));
});

it('never lets the shadowed callback fail the deciding one', function (): void {
    config()->set('access.gate_shadow', true);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(null, new RuntimeException('legacy broke')));
    Log::spy();

    expect(gateCallback()(new PermissionedFakeUser(1, ['super-admin']), 'any-ability'))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('access.gate_shadow_failed', Mockery::on(
        static fn (array $context): bool => $context['exception'] === RuntimeException::class && $context['ability'] === 'any-ability',
    ));
});

it('hands the legacy callback the Gate arguments untouched, and the log a list of their types', function (bool $isShadow): void {
    config()->set('access.use_package_gate', false);
    config()->set('access.gate_shadow', $isShadow);
    app()->instance(LegacyGateBefore::class, $legacy = new FixedLegacyGateBefore(true));
    $post = new stdClass;
    Log::spy();

    gateCallback()(new PermissionedFakeUser(2, ['member']), 'update', ['post' => $post]);

    expect($legacy->receivedArguments)->toBe(['post' => $post]);

    if ($isShadow) {
        Log::shouldHaveReceived('warning')->once()->with('access.gate_shadow_mismatch', Mockery::on(
            static fn (array $context): bool => $context['argument_types'] === [stdClass::class],
        ));
    }
})->with(['alone' => false, 'in shadow mode' => true]);

it('decides through the Gate exactly as without shadow mode, Policies and named arguments included', function (array $arguments, bool $expected): void {
    Gate::policy(ShadowPost::class, ShadowPostPolicy::class);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(null));
    $superAdmin = new PermissionedFakeUser(1, ['super-admin']);
    AccessContext::enter(new ActorContext(ActorKind::WebSession, $superAdmin));
    Log::spy();
    $gate = app(GateContract::class);
    $register = (new ReflectionMethod(AccessServiceProvider::class, 'registerGateBefore'))->getClosure(new AccessServiceProvider(app()));
    $beforeCallbacks = (new ReflectionClass($gate))->getProperty('beforeCallbacks');
    $decisions = [];

    foreach ([false, true] as $isShadow) {
        config()->set('access.gate_shadow', $isShadow);
        $beforeCallbacks->setValue($gate, []);
        $register();
        $decisions[] = $gate->forUser($superAdmin)->allows('update', $arguments);
    }

    expect($decisions)->toBe([$expected, $expected]);
})->with([
    'named argument: super-admin passes' => [['post' => new ShadowPost], true],
    'positional argument: the Policy decides' => [[new ShadowPost], false],
]);

it('keeps the decision when the shadow log or the actor lookup throws', function (string $failingService): void {
    config()->set('access.gate_shadow', true);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(null));

    if ($failingService === 'log') {
        Log::shouldReceive('warning')->andThrow(new RuntimeException('disk full'));
    } else {
        // GateBefore reads the actor too, so here it is the shadowed one:
        // its failure and then the log context of that failure both throw.
        config()->set('access.use_package_gate', false);
        app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(true));
        $actors = Mockery::mock(ActorResolver::class);
        $actors->shouldReceive('current')->andThrow(new RuntimeException('no actor'));
        app()->instance(ActorResolver::class, $actors);
    }

    expect(gateCallback()(new PermissionedFakeUser(1, ['super-admin']), 'any-ability'))->toBeTrue();
})->with(['log', 'actor resolver']);

it('compares guest decisions too', function (): void {
    config()->set('access.use_package_gate', false);
    config()->set('access.gate_shadow', true);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(true));
    Log::spy();

    expect(gateCallback()(null, 'any-ability'))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('access.gate_shadow_mismatch', Mockery::on(
        static fn (array $context): bool => $context['package_decision'] === null && $context['legacy_decision'] === true,
    ));
});

it('registers the legacy callback at boot when the package gate is off', function (): void {
    config()->set('access.use_package_gate', false);
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(true));
    $gate = app(GateContract::class);
    (new ReflectionClass($gate))->getProperty('beforeCallbacks')->setValue($gate, []);

    (new ReflectionMethod(AccessServiceProvider::class, 'registerGateBefore'))->invoke(new AccessServiceProvider(app()));

    expect($gate->forUser(new PermissionedFakeUser(2, ['member']))->allows('any-ability'))->toBeTrue();
});

it('uses the default channel when the shadow channel is empty', function (): void {
    config()->set('access.gate_shadow', true);
    config()->set('access.gate_shadow_log_channel', '');
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(false));
    Log::spy();

    gateCallback()(new PermissionedFakeUser(2, ['member']), 'any-ability');

    Log::shouldNotHaveReceived('channel');
    Log::shouldHaveReceived('warning')->once();
    config()->set('access.gate_shadow_log_channel', null);
});

it('writes shadow logs to the configured channel', function (): void {
    config()->set('access.gate_shadow', true);
    config()->set('access.gate_shadow_log_channel', 'access-shadow');
    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(false));
    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('warning')->once()->with('access.gate_shadow_mismatch', Mockery::type('array'));
    Log::shouldReceive('channel')->once()->with('access-shadow')->andReturn($channel);

    gateCallback()(new PermissionedFakeUser(2, ['member']), 'any-ability');

    config()->set('access.gate_shadow_log_channel', null);
});

it('fails at boot when the legacy callback is needed but not bound, or a switch is not boolean', function (): void {
    $checks = app(AccessBootChecks::class);
    $config = app(ConfigRepository::class);
    $checks->assertGateSelection($config);

    config()->set('access.use_package_gate', false);
    expect(fn () => $checks->assertGateSelection($config))->toThrow(
        RuntimeException::class,
        'access.use_package_gate = false or access.gate_shadow = true requires ['.LegacyGateBefore::class.'] to be bound before boot.',
    );

    config()->set('access.use_package_gate', true);
    config()->set('access.gate_shadow', true);
    expect(fn () => $checks->assertGateSelection($config))->toThrow(RuntimeException::class, LegacyGateBefore::class);

    app()->instance(LegacyGateBefore::class, new FixedLegacyGateBefore(null));
    $checks->assertGateSelection($config);

    foreach (['access.use_package_gate', 'access.gate_shadow'] as $key) {
        config()->set($key, 'false');
        expect(fn () => $checks->assertGateSelection($config))->toThrow(RuntimeException::class, $key);
        config()->set($key, $key === 'access.use_package_gate');
    }

    expect(fn () => $checks->assert())->not->toThrow(RuntimeException::class);
});

it('keeps exactly one Gate::before callback (AC1)', function (): void {
    $gate = app(GateContract::class);
    $callbacks = (new ReflectionClass($gate))->getProperty('beforeCallbacks')->getValue($gate);

    expect($callbacks)->toHaveCount(1);
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Testing\AssertsSpatieTeamSchema;

it('is a no-op when Spatie teams are off', function (): void {
    config()->set('permission.teams', false);

    $asserter = new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        app('db')->connection(),
    );

    $asserter->assertWhenTeamsEnabled();
    expect(true)->toBeTrue();
});

it('fails when teams are on and team_foreign_key is not organisation_id (A3)', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'team_id');

    $asserter = new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        app('db')->connection(),
    );

    expect(fn () => $asserter->assertWhenTeamsEnabled())
        ->toThrow(RuntimeException::class, 'organisation_id');
});

it('passes when teams are on with organisation_id and uuid columns on pgsql-compatible stubs', function (): void {
    config()->set('permission.teams', true);
    config()->set('permission.column_names.team_foreign_key', 'organisation_id');
    config()->set('permission.table_names', [
        'roles' => 'roles',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
    ]);

    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->uuid('organisation_id')->nullable();
        });
    }

    $asserter = new AssertsSpatieTeamSchema(
        app(ConfigRepository::class),
        app('db')->connection(),
    );

    $asserter->assertWhenTeamsEnabled();
    expect(true)->toBeTrue();

    foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
        Schema::dropIfExists($table);
    }
});

<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Testing\AssertsGroupTablesSchema;

beforeEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('user_group_role');
    Schema::dropIfExists('user_group_user');
    Schema::dropIfExists('user_groups');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('users');
});

it('passes AC17 when the package migration shape is present', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_120000_create_user_groups_tables.php';
    $migration->up();

    (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape();
    expect(true)->toBeTrue();
})->group('engines');

it('fails AC17 when a required pivot index is missing', function (): void {
    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->dateTime('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['user_group_id', 'user_id']);
        // Missing expires_at and user_id indexes on purpose.
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->unsignedBigInteger('role_id');
        $table->timestamps();
        $table->unique(['user_group_id', 'role_id']);
        $table->index('role_id');
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, 'AC17');
})->group('engines');

it('fails AC17 when a surrogate id appears on a pivot', function (): void {
    Schema::create('user_groups', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('user_group_user', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->dateTime('expires_at')->nullable()->index();
        $table->timestamps();
        $table->unique(['user_group_id', 'user_id']);
        $table->index('user_id');
    });
    Schema::create('user_group_role', function (Blueprint $table): void {
        $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
        $table->unsignedBigInteger('role_id');
        $table->timestamps();
        $table->unique(['user_group_id', 'role_id']);
        $table->index('role_id');
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });

    expect(fn () => (new AssertsGroupTablesSchema(app('db')->connection()))->assertShape())
        ->toThrow(RuntimeException::class, 'surrogate');
})->group('engines');

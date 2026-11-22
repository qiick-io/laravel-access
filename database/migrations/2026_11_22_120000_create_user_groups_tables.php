<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Package-owned group tables (standards A12 / decision 2026-11-22, Q5).
 * Names are fixed: user_groups, user_group_user, user_group_role — never
 * configurable. Spatie's tables stay Spatie's; this migration never creates
 * or alters them. role_id references permission.table_names.roles
 * (default "roles").
 */
return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');
        $rolesTable = is_string($rolesTable) && $rolesTable !== '' ? $rolesTable : 'roles';

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
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('user_group_role', function (Blueprint $table) use ($rolesTable): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->unique(['user_group_id', 'role_id']);
            $table->index('role_id');

            $table->foreign('role_id')
                ->references('id')
                ->on($rolesTable)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_role');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
};

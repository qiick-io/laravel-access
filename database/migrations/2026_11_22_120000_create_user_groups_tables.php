<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Package-owned group tables (standards A12 / decision 2026-11-22). Defaults
 * are user_groups, user_group_user, user_group_role via access.table_names
 * (config only — never env). Spatie's tables stay Spatie's — this migration
 * never creates or alters them. role_id references
 * permission.table_names.roles (default "roles").
 */
return new class extends Migration
{
    public function up(): void
    {
        $groupsTable = $this->accessTableName('groups', 'user_groups');
        $groupUserTable = $this->accessTableName('group_user', 'user_group_user');
        $groupRoleTable = $this->accessTableName('group_role', 'user_group_role');

        $rolesTable = config('permission.table_names.roles', 'roles');
        $rolesTable = is_string($rolesTable) && $rolesTable !== '' ? $rolesTable : 'roles';

        Schema::create($groupsTable, function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($groupUserTable, function (Blueprint $table) use ($groupsTable): void {
            $table->foreignId('user_group_id')->constrained($groupsTable)->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create($groupRoleTable, function (Blueprint $table) use ($groupsTable, $rolesTable): void {
            $table->foreignId('user_group_id')->constrained($groupsTable)->cascadeOnDelete();
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
        Schema::dropIfExists($this->accessTableName('group_role', 'user_group_role'));
        Schema::dropIfExists($this->accessTableName('group_user', 'user_group_user'));
        Schema::dropIfExists($this->accessTableName('groups', 'user_groups'));
    }

    private function accessTableName(string $key, string $default): string
    {
        $value = config("access.table_names.{$key}", $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
};

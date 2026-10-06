<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('avatar_path')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->timestampsTz();
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('install_id');
            $table->string('platform');
            $table->string('push_token')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'install_id']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('token_hash')->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            // Deliberately not a foreign key to this same table: the rotation chain writes
            // this link before the successor row exists in some orderings, and a
            // self-referential FK would force a two-step write for no benefit.
            $table->uuid('replaced_by_id')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
            // ADR 0003 makes revocation per-device, so "every token for device X" is a
            // real access path and needs its own index.
            $table->index('device_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
    }
};

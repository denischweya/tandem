<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every timestamptz column across all four Cycle 1 migrations is created at
        // precision 6 (microseconds), explicitly - Builder::$defaultTimePrecision is 0, and
        // Postgres *rounds* rather than truncates: '...12:00:00.6+00'::timestamptz(0) becomes
        // '...12:00:01+00', so an unqualified timestamptz(0) column can store a value in the
        // future relative to when it was written, and two rows written microseconds apart in
        // one transaction can tie. 6 matches PHP's own DateTimeImmutable resolution. See
        // ConstraintTest.php for the sweep that asserts this everywhere.
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->timestampTz('email_verified_at', 6)->nullable();
            $table->timestampTz('suspended_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);
        });

        // Email uniqueness alone (above) is byte-exact: Alice@Example.com and
        // alice@example.com are distinct rows to a plain btree unique index. Email::fromString()
        // lowercases on the way in, but nothing at the database level stops a write path that
        // bypasses the value object from storing a mixed-case duplicate and splitting every
        // lowercase lookup. A CHECK is preferred over a functional UNIQUE INDEX ((lower(email))):
        // the functional index blocks the duplicate but still permits *storing*
        // "Alice@Example.com", a value Email::fromString() can never produce and that every
        // lowercase lookup would then miss; the CHECK rejects the write outright.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase CHECK (email = lower(email))');

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            // 80, not the framework default of 255: DisplayName::MAX_LENGTH is 80, and
            // Postgres varchar(n) counts characters (matching mb_strlen), so this is the
            // database backstop for the same rule the value object already enforces. The
            // migration window closes with Wave 0; widen later if the product needs it.
            $table->string('display_name', 80);
            $table->string('avatar_path')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->timestampsTz(6);
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('install_id');
            $table->string('platform');
            $table->string('push_token')->nullable();
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['user_id', 'install_id']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Nullable + nullOnDelete, not NOT NULL + restrictOnDelete: the latter would
            // require every refresh token to be bound to a device row, but devices.install_id
            // is itself NOT NULL under UNIQUE(user_id, install_id), and the web BFF (ADR 0004)
            // has no install_id concept - there is no web device row to bind to. Forcing NOT
            // NULL here would mean inventing a synthetic install_id per browser, which no spec
            // or ADR defines. If per-device binding becomes an invariant, that has to be
            // decided in ADR 0003 and web's install_id defined in the same change.
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('token_hash')->unique();
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('revoked_at', 6)->nullable();
            // Deliberately not a foreign key to this same table: the rotation chain writes
            // this link before the successor row exists in some orderings, and a
            // self-referential FK would force a two-step write for no benefit.
            $table->uuid('replaced_by_id')->nullable();
            $table->timestampsTz(6);

            $table->index(['user_id', 'revoked_at']);
            // ADR 0003 makes revocation per-device, so "every token for device X" is a
            // real access path and needs its own index.
            $table->index('device_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('used_at', 6)->nullable();
            $table->timestampsTz(6);
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

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
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // restrictOnDelete, not cascade: a user who still owns a Plan cannot be
            // deleted until ownership transfers. This is what makes the account-deletion
            // ownership-transfer rule enforceable rather than aspirational.
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('visibility')->default('invite_only');
            $table->string('timezone');
            $table->string('cover_image_path')->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index('owner_id');
            $table->index(['status', 'updated_at']);
        });

        Schema::create('plan_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->string('status')->default('invited');
            $table->timestampTz('joined_at', 6)->nullable();
            $table->jsonb('notification_settings')->default('{}');
            $table->timestampsTz(6);

            $table->unique(['plan_id', 'user_id']);
            $table->index('user_id');
            $table->index('plan_id');
        });

        Schema::create('plan_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade: hard-deleting the sender must not delete the
            // invite record, including invites already accepted. That would destroy the
            // historical "who invited whom" trail, and it would be inconsistent with
            // accepted_by below, which is preserved the same way.
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('token_hash')->unique();
            $table->string('role')->default('member');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('accepted_at', 6)->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index('plan_id');
        });

        // Byte-exact uniqueness, same reasoning as users.email above: nullable, because
        // not every invite carries an email (link-only invites), so the CHECK must allow
        // NULL through rather than forcing a value.
        DB::statement('ALTER TABLE plan_invites ADD CONSTRAINT plan_invites_email_lowercase CHECK (email IS NULL OR email = lower(email))');

        Schema::create('plan_activity', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            // Deliberately not a foreign key, and this table has created_at only (no
            // updated_at): both serve immutability. There is no update path, and
            // anonymising a deleted user's activity means nulling actor_id without the
            // row being cascade-deleted out from under the feed.
            $table->uuid('actor_id')->nullable();
            $table->string('activity_type');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at', 6);

            $table->index(['plan_id', 'created_at']);
            // Spec §9.3 step 3 anonymises plan_activity with an UPDATE ... WHERE actor_id = ?
            // that runs inside the single transaction DELETE /api/v1/me uses for steps 1-4.
            // Without this index that UPDATE seq-scans the highest-volume table in the schema
            // from inside a user-facing request. Single-column: actor_id alone is the only
            // predicate that UPDATE filters on.
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_activity');
        Schema::dropIfExists('plan_invites');
        Schema::dropIfExists('plan_members');
        Schema::dropIfExists('plans');
    }
};

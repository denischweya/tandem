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
        // Created before `plans`, which gains a nullable group_id FK into this table below.
        // A Group is a named, reusable set of people a user plans with (e.g. "The Boys",
        // "Family") - see docs/decisions/0007-groups-supersede-blueprint-57-navigation.md
        // for the product evidence. This table is schema-only: no Group endpoint, model or
        // policy exists yet (see that ADR for which plan must build them).
        Schema::create('groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // restrictOnDelete, mirroring plans.owner_id below: the same "a user who still
            // owns something cannot be deleted until ownership resolves" invariant applies
            // here. Unlike plans, though, spec §9.3's account-deletion procedure has no step
            // that transfers Group ownership the way its step 2 does for Plan ownership -
            // Groups have no endpoints yet, so no row can carry a live owner_id in Cycle 1
            // and this cannot fire today. Whichever plan first builds Group endpoints MUST
            // extend §9.3 with a Group-ownership-transfer branch mirroring the Plan one, or
            // DELETE /api/v1/me will throw a QueryException the day the first Group owner
            // tries to delete their account. Flagged, not fixed, here: fixing it means
            // designing that transfer rule, which is application behaviour, not schema.
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->timestampsTz(6);

            // "Groups this user owns" - mirrors plans(owner_id) immediately below.
            $table->index('owner_id');
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // restrictOnDelete, not cascade: a user who still owns a Plan cannot be
            // deleted until ownership transfers. This is what makes the account-deletion
            // ownership-transfer rule enforceable rather than aspirational.
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            // nullable + nullOnDelete, the opposite choice from owner_id above, deliberately:
            // a Plan need not come from a Group (most won't, in Cycle 1 - nothing creates a
            // Group yet), so NOT NULL is wrong; and deleting a Group must not delete the
            // Plans that came from it - a Plan's link to a Group is incidental, not
            // load-bearing, unlike its link to its owner. SET NULL is therefore the correct
            // rule, not CASCADE and not RESTRICT.
            $table->foreignUuid('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('visibility')->default('invite_only');
            $table->string('timezone');
            $table->string('cover_image_path')->nullable();
            $table->timestampTz('archived_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index('owner_id');
            // "Plans for this group" - the companion access path to groups(owner_id) above
            // and group_members(user_id)/(group_id) below.
            $table->index('group_id');
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

        // Mirrors plan_members immediately above: same cascade-both-ways behaviour (leaving
        // a Group or deleting your account both just remove the membership row, never the
        // Group or the user), same unique pair constraint, same both-direction indexing.
        Schema::create('group_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz(6);

            $table->unique(['group_id', 'user_id']);
            // "Groups this user is in" - the companion access path to plans(group_id) above.
            $table->index('user_id');
            $table->index('group_id');
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
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('plan_members');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('groups');
    }
};

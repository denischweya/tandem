<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR 0011 reopens the migration freeze narrowly for these two tables. Do not add, edit or
// drop any other table under this file's authority.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            // RESTRICT, not CASCADE: a message must survive the author's own account
            // deletion, the same way plan_activity's actor_id survives it (by anonymising,
            // not deleting). See docs/specs/2026-10-29-plan-chat-design.md §3 — the §9.3
            // anonymisation step must be extended to this column; this migration only states
            // the constraint.
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            // 6 = microsecond precision: PageCursor compares cursor keys at microsecond
            // resolution (app/Domain/Shared/PageCursor.php), and a less precise column would
            // put two rows written in the same millisecond on the same page boundary.
            $table->timestampTz('edited_at', 6)->nullable();
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['plan_id', 'created_at']);
        });

        Schema::create('plan_message_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('plan_messages')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction');
            $table->timestampTz('created_at', 6);

            $table->unique(['message_id', 'user_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_message_reactions');
        Schema::dropIfExists('plan_messages');
    }
};

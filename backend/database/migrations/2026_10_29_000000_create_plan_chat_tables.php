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
            // SUPERSEDED by 2026_10_30_000000_make_plan_messages_user_id_anonymizable.php
            // (ADR 0012), which makes this column nullable with ON DELETE SET NULL. The intent
            // below was right and this shape could not reach it: a message must survive the
            // author's own account deletion by ANONYMISING, the way plan_activity.actor_id
            // does - and plan_activity.actor_id is nullable with no foreign key at all, so a
            // NOT NULL column had nowhere to write the anonymised state. The consequence
            // landed at the day-30 hard purge, not at account deletion; the later migration's
            // own header records it in full. Left as written rather than edited, because a
            // migration that has run is history: read it with its successor.
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

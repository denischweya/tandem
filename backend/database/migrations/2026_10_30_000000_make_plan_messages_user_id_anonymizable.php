<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR 0012 reopens the migration freeze for exactly ONE column-shape change:
// plan_messages.user_id. No other table and no other column may be added, edited or dropped
// under this file's authority - the same narrow grant ADR 0009 and ADR 0011 each made.
//
// 2026_10_29_000000_create_plan_chat_tables.php created this column NOT NULL with a RESTRICT
// foreign key, and said in its own comment that this mirrors plan_activity.actor_id. It does
// not: actor_id is `$table->uuid('actor_id')->nullable()` with no foreign key at all, which is
// what lets DeleteAccount's step 4 anonymise it. A NOT NULL column has nowhere to write that
// state, so plan_messages was never anonymised - and because step 5 of DeleteAccount is only a
// SOFT delete, nothing noticed until the day-30 hard purge
// (App\Console\Commands\PurgeDeletedUsersCommand's $user->forceDelete()), which hit
// plan_messages_user_id_foreign for any user who had ever sent one message. The command's
// per-user catch recorded that as a failure and moved on, so the same user was refused again
// at every 03:00 run, for ever, with no operator action that could resolve it.
return new class extends Migration
{
    /**
     * Nullable WITH a real foreign key - deliberately not either of the two shapes already in
     * this schema:
     *
     * - plan_activity.actor_id's "nullable, no FK at all" suits an append-only table written
     *   only by the application. plan_messages is user-authored content read back with its
     *   author's name attached, which is worth having the database enforce.
     * - plan_message_reactions.user_id's CASCADE is right for a reaction (it has no value once
     *   its author is gone) and wrong for a message, whose text is the thing both ADR 0011 and
     *   the chat design document exist to preserve.
     *
     * So: SET NULL. The author reference goes, the message stays.
     *
     * Two statements rather than one ->change() call: the foreign key has to be dropped and
     * re-added in any case (RESTRICT -> SET NULL is not something ALTER COLUMN can express),
     * and dropping it before the column is altered keeps the two concerns in the order a
     * reader expects.
     */
    public function up(): void
    {
        Schema::table('plan_messages', function (Blueprint $table): void {
            // Named by column, not by string: dropForeign(['user_id']) builds Laravel's own
            // conventional 'plan_messages_user_id_foreign' rather than restating it here, so
            // this does not depend on a name spelled in two places.
            $table->dropForeign(['user_id']);
        });

        Schema::table('plan_messages', function (Blueprint $table): void {
            // The whole definition restated, because ->change() in Laravel 11+ replaces a
            // column's attributes with exactly what is spelled here and drops anything
            // omitted. `uuid` is what foreignUuid() created; ->nullable() is the change.
            $table->uuid('user_id')->nullable()->change();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Back to NOT NULL with RESTRICT, which means this down() can only run while no row holds
     * a null user_id. That is a property of the shape being reverted, not an oversight: rolling
     * back to a column that cannot represent an anonymised author must fail loudly if an
     * anonymised author exists, rather than invent an owner for somebody's message.
     */
    public function down(): void
    {
        Schema::table('plan_messages', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('plan_messages', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable(false)->change();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};

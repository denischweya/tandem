<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $message_id
 * @property string $user_id
 * @property string $reaction
 * @property Carbon $created_at
 */
final class PlanMessageReaction extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    public $timestamps = false;

    /**
     * 'datetime', not left uncast: the column is NOT NULL with no DB default, and
     * $timestamps = false above means Eloquent's own touch() never fills it, so
     * EloquentPlanMessageRepository::addReaction() assigns Carbon::now() to it directly -
     * which needs this cast the same way PlanMessage's own edited_at/deleted_at do.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<PlanMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(PlanMessage::class, 'message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

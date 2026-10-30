<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $plan_id
 * @property string|null $user_id Null once the author's account is deleted - ADR 0012.
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class PlanMessage extends Model
{
    use HasUuids;

    /** Nothing is mass-assignable. ['*'], not [] — see Plan's and PlanInvite's own docblocks. */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlanMessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(PlanMessageReaction::class, 'message_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlanMessageReaction extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    public $timestamps = false;

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

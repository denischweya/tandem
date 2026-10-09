<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable (§51), with one exception: §9.3 step 4 nulls actor_id when the actor deletes
 * their account. The row is never removed, so the feed stays intelligible to the members who
 * remain - which is also why actor_id is a plain uuid column rather than a foreign key, so
 * no cascade can take the row out from under them.
 *
 * $actor_id is nullable for that same reason, and there is no actor() relation returning a
 * guaranteed User: an anonymised row has no actor to return.
 *
 * @property string $id
 * @property string $plan_id
 * @property string|null $actor_id
 * @property string $activity_type
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed> $metadata
 * @property Carbon $created_at
 */
final class PlanActivity extends Model
{
    /** @use HasFactory<PlanActivityFactory> */
    use HasFactory, HasUuids;

    protected $table = 'plan_activity';

    // plan_activity has created_at only - there is no update path for an immutable record,
    // so there is nothing for updated_at to mean. Same shape as OutboxEvent.
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * created_at is cast explicitly: Eloquent only date-casts the timestamp columns when
     * usesTimestamps() is true, which it is not here, so without this the column would come
     * back as a raw string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, reusable set of people a user plans with (ADR 0007). Schema-only until Wave 2
 * builds the endpoints; this model exists because groups.owner_id is NOT NULL with RESTRICT,
 * so Postgres refuses to hard-delete a user who still owns a Group and §9.3 therefore needs
 * a Group-ownership-transfer branch. The plan-tables migration flagged that gap explicitly
 * and left it to whichever plan first needed it - this is that plan.
 *
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, GroupMember> $members
 */
final class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

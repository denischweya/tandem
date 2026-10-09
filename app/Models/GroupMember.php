<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * There is deliberately no joined_at and no status here, unlike plan_members: joining a
 * Group is not a two-step invitation, so created_at IS the join date - which is what
 * DeleteAccount::resolveGroupOwnership() orders by to find the longest-standing member.
 *
 * @property string $id
 * @property string $group_id
 * @property string $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class GroupMember extends Model
{
    /** @use HasFactory<GroupMemberFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

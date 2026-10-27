<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * $joined_at is nullable because an invited member has not joined yet - which is exactly why
 * §9.3 step 2 picks a successor from `active` rows only. See DeleteAccount::successorFor().
 *
 * Nothing is mass-assignable here, `role` least of all. See Plan's docblock for the whole
 * argument, including why an empty #[Fillable] allowlist does not do this.
 *
 * @property string $id
 * @property string $plan_id
 * @property string $user_id
 * @property string $role
 * @property string $status
 * @property Carbon|null $joined_at
 * @property array<string, mixed> $notification_settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class PlanMember extends Model
{
    /** @use HasFactory<PlanMemberFactory> */
    use HasFactory, HasUuids;

    /**
     * Nothing is mass-assignable. ['*'], not [] - see the class docblock on Plan.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'notification_settings' => 'array',
        ];
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
}

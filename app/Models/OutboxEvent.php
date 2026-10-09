<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $published_at
 * @property int $attempts
 * @property string|null $last_error
 */
final class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory, HasUuids;

    // outbox_events has no created_at/updated_at: occurred_at is the only time this table
    // cares about, and the migration reflects that.
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}

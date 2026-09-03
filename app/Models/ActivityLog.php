<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du journal d'activité (voir migration create_activity_logs). Écrite par
 * le middleware RecordActivity ; jamais modifiée (created_at seul).
 */
#[Fillable([
    'user_id',
    'method',
    'route',
    'target_type',
    'target_id',
    'ip',
    'payload_digest',
    'status_code',
    'created_at',
])]
class ActivityLog extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'status_code' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

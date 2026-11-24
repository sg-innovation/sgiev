<?php

namespace App\Models;

use App\Enums\StationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Append-only history of station / charger status changes. */
class StatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['loggable_type', 'loggable_id', 'from_status', 'to_status', 'reason', 'until', 'changed_by', 'ip'];

    protected function casts(): array
    {
        return [
            'from_status' => StationStatus::class,
            'to_status' => StationStatus::class,
            'until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Status logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Status logs are append-only.'));
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

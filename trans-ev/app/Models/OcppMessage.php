<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One OCPP frame in or out (append-only). */
class OcppMessage extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [2 => 'Call', 3 => 'Result', 4 => 'Error'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }
}

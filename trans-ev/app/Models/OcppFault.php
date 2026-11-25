<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An error reported by a charge point (StatusNotification errorCode), until it clears. */
class OcppFault extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(ChargerWorkOrder::class);
    }
}

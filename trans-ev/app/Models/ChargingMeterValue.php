<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A meter reading during a charging session (start, interim sample, stop). Entered by staff now, sent by OCPP later. */
class ChargingMeterValue extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['measured_at' => 'datetime', 'register_kwh' => 'decimal:3', 'energy_kwh' => 'decimal:3', 'power_kw' => 'decimal:2'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class, 'charging_session_id');
    }
}

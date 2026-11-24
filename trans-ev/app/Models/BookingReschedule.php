<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of a booking's slot or charger (append-only history). */
class BookingReschedule extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_start' => 'datetime', 'from_end' => 'datetime', 'to_start' => 'datetime', 'to_end' => 'datetime', 'created_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function fromCharger(): BelongsTo
    {
        return $this->belongsTo(Charger::class, 'from_charger_id')->withTrashed();
    }

    public function toCharger(): BelongsTo
    {
        return $this->belongsTo(Charger::class, 'to_charger_id')->withTrashed();
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

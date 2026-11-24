<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who checked a booking in, how (staff scan, charger QR, manual) and when. */
class CheckInRecord extends Model
{
    public const METHODS = ['staff_scan' => 'Staff scanned the customer’s QR', 'charger_scan' => 'Customer scanned the charger QR', 'manual' => 'Checked in by staff'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}

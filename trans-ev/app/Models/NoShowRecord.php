<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A booking marked as a no-show: automatically or by staff, the grace given, and what was refunded or kept. */
class NoShowRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime', 'late_notice_at' => 'datetime', 'late_eta_at' => 'datetime',
            'paid_amount' => 'decimal:2', 'refund_amount' => 'decimal:2', 'retained_amount' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}

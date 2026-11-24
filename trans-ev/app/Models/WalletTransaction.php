<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One wallet movement (append-only ledger). */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'recharge' => 'Recharge (online)', 'manual_credit' => 'Funds added by Trans EV', 'manual_debit' => 'Adjustment by Trans EV',
        'platform_fee' => 'Platform fee for a booking', 'fee_reversal' => 'Platform fee returned (refund)',
        'coupon_credit' => 'Trans EV coupon reimbursed', 'subscription' => 'Plan subscription',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Wallet transactions cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Wallet transactions cannot be deleted.'));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

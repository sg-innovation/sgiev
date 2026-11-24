<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A customer's place in a station's waiting list for a day and time window. */
class WaitlistEntry extends Model
{
    public const STATUSES = [
        'waiting' => 'Waiting', 'offered' => 'Slot offered', 'booked' => 'Booked', 'passed' => 'Offer passed on',
        'expired' => 'Window ended', 'cancelled' => 'Left the list',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'vehicle' => 'array', 'customer' => 'array', 'offered_at' => 'datetime', 'offer_expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (WaitlistEntry $e) => $e->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['waiting', 'offered'], true);
    }
}

<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Booking extends Model
{
    use HasFactory, LogsActivity;

    // Bookings are created only by BookingService; nothing is mass-assignable from requests.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'blocked_until' => 'datetime',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'customer_snapshot' => 'array',
            'vehicle_snapshot' => 'array',
            'cancellation_policy' => 'array',
            'price_lines' => 'array',
            'parking_amount' => 'decimal:2',
            'no_show_at' => 'datetime',
            'est_kwh' => 'decimal:3',
            'rate_per_kwh' => 'decimal:2',
            'energy_amount' => 'decimal:2',
            'session_fee' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $b) {
            $b->uuid ??= (string) Str::uuid();
            $b->code ??= static::newCode();
        });
    }

    /** Human-friendly, non-sequential booking code, e.g. TEV-261124-7KQ3M. */
    public static function newCode(): string
    {
        do {
            // Random part avoids look-alike characters (0/O, 1/I/L); the date part is left untouched.
            $random = strtr(Str::upper(Str::random(5)), ['0' => '7', 'O' => 'Q', 'I' => 'K', 'L' => 'M', '1' => '9']);
            $code = 'TEV-'.now(Station::TIMEZONE)->format('ymd').'-'.$random;
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class)->withTrashed();
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class)->withTrashed();
    }

    public function connectorPoint(): BelongsTo
    {
        return $this->belongsTo(Connector::class, 'connector_id')->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest('id');
    }

    public function ruleAcceptance(): HasOne
    {
        return $this->hasOne(RuleAcceptance::class);
    }

    /** Bookings that currently occupy their charger (unexpired holds, confirmed, charging). */
    public function scopeOccupying(Builder $q): Builder
    {
        return $q->where(function (Builder $q) {
            $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
                ->orWhere(fn (Builder $q) => $q->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now()));
        });
    }

    public function scopeOverlapping(Builder $q, \DateTimeInterface $start, \DateTimeInterface $blockedUntil): Builder
    {
        return $q->where('start_at', '<', $blockedUntil)->where('blocked_until', '>', $start);
    }

    public function isHoldActive(): bool
    {
        return $this->status === BookingStatus::Held && $this->hold_expires_at?->isFuture();
    }

    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, [BookingStatus::Held, BookingStatus::Confirmed], true) && $this->start_at->isFuture();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'start_at', 'end_at', 'charger_id', 'cancel_reason'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

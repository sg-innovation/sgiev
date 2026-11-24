<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Services\GstService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Discount code. company_id null = platform-funded (any operator); otherwise operator-funded. */
class Coupon extends Model
{
    use LogsActivity, SoftDeletes;

    public const TYPES = ['percent' => 'Percentage off', 'flat' => 'Flat amount off'];

    /** Booking states that count as a use of the coupon (cancelled and expired bookings free it up again). */
    public const COUNTED_STATUSES = [BookingStatus::Held, BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow];

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'type', 'value', 'max_discount', 'min_order',
        'station_ids', 'charger_type', 'starts_at', 'ends_at', 'usage_limit', 'per_user_limit',
        'first_booking_only', 'is_public', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_order' => 'decimal:2',
            'station_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'first_booking_only' => 'boolean',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Coupon $c) {
            $c->code = strtoupper(preg_replace('/\s+/', '', (string) $c->code));
            $c->created_by ??= auth()->id();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Bookings that use up the coupon. */
    public function redemptions(): HasMany
    {
        return $this->bookings()->where(fn (Builder $q) => $q
            ->whereIn('status', array_filter(self::COUNTED_STATUSES, fn ($s) => $s !== BookingStatus::Held))
            ->orWhere(fn (Builder $h) => $h->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now())));
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function fundedBy(): string
    {
        return $this->company_id === null ? 'platform' : 'operator';
    }

    /** Discount on a pre-tax subtotal (string money), never more than the subtotal. */
    public function discountOn(string $subtotal): string
    {
        $d = $this->type === 'percent'
            ? GstService::round(bcdiv(bcmul($subtotal, (string) $this->value, 6), '100', 6))
            : (string) $this->value;
        if ($this->type === 'percent' && $this->max_discount !== null && bccomp($d, (string) $this->max_discount, 2) > 0) {
            $d = (string) $this->max_discount;
        }

        // Keep at least ₹1 payable: the payment gateway cannot take a zero-value payment.
        $max = bccomp($subtotal, '1.00', 2) > 0 ? bcsub($subtotal, '1.00', 2) : '0.00';

        return bccomp($d, $max, 2) > 0 ? GstService::round($max) : GstService::round($d);
    }

    /** Short customer-facing summary, e.g. "20% off (up to ₹100)". */
    public function headline(): string
    {
        $v = rtrim(rtrim((string) $this->value, '0'), '.');
        $text = $this->type === 'percent' ? "{$v}% off" : "₹{$v} off";
        if ($this->type === 'percent' && $this->max_discount !== null) {
            $text .= ' (up to ₹'.rtrim(rtrim((string) $this->max_discount, '0'), '.').')';
        }
        if ($this->min_order !== null) {
            $text .= ' on ₹'.rtrim(rtrim((string) $this->min_order, '0'), '.').'+';
        }

        return $text;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

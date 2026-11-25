<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Payment extends Model
{
    use LogsActivity;

    public const CREATED = 'created';

    public const PENDING = 'pending';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'client_secret' => 'encrypted',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_enquiry_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Payment $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Staff member who took cash or UPI at the station. */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->latest('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCESS, self::FAILED], true);
    }

    /** Amount already refunded or being refunded. */
    public function refundedAmount(): string
    {
        return (string) $this->refunds()->whereIn('status', [Refund::INITIATED, Refund::SUCCESS])->sum('amount');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'gateway_status', 'paid_amount', 'gateway_txn_id', 'verified_via'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** "UPI · UPI ra•••@okicici" / "CARD · VISA •••• 4242" for receipts and invoices (never the full number or ID). */
    public function methodLabel(): ?string
    {
        $parts = array_values(array_filter([$this->payment_mode ? strtoupper((string) $this->payment_mode) : null, $this->payment_instrument]));

        return $parts ? implode(' · ', array_unique($parts)) : null;
    }
}

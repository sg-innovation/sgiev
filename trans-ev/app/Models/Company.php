<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A charging company (tenant). One company is the platform operator (Trans EV / Tranzacta). */
class Company extends Model
{
    use LogsActivity;

    protected $fillable = [
        'legal_name', 'trade_name', 'slug', 'gstin', 'pan', 'cin',
        'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pincode',
        'email', 'phone', 'website', 'support_phone', 'support_email', 'logo_path',
        'invoice_prefix', 'bank_details', 'social_links', 'invoice_terms',
        'commission_percent', 'per_booking_fee', 'fee_terms_note', 'billing_mode',
        'sabpaisa_env', 'sabpaisa_merchant_id', 'sabpaisa_api_key', 'sabpaisa_secret_key', 'sabpaisa_webhook_secret',
        'payment_gateway', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
    ];

    // is_platform, status and onboarded_at change only through explicit platform actions.

    protected $hidden = ['sabpaisa_api_key', 'sabpaisa_secret_key', 'sabpaisa_webhook_secret', 'razorpay_key_secret', 'razorpay_webhook_secret'];

    public const STATUSES = [
        'pending' => 'Awaiting review',
        'changes_requested' => 'Changes requested',
        'active' => 'Active',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
    ];

    protected function casts(): array
    {
        return [
            'bank_details' => 'encrypted:array', // settlement bank details are sensitive
            'social_links' => 'array',
            'is_platform' => 'boolean',
            'onboarded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'sabpaisa_api_key' => 'encrypted',
            'sabpaisa_secret_key' => 'encrypted',
            'sabpaisa_webhook_secret' => 'encrypted',
            'razorpay_key_secret' => 'encrypted',
            'razorpay_webhook_secret' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $c) {
            $c->slug ??= Str::slug($c->trade_name).'-'.Str::lower(Str::random(4));
            $c->payment_gateway ??= in_array($d = \App\Support\Settings::get('payments.default_gateway'), ['sabpaisa', 'razorpay'], true) ? $d : 'sabpaisa';
        });
        static::saving(function (Company $c) {
            // Record when the commission terms were (re)agreed.
            if ($c->exists && $c->isDirty(['commission_percent', 'per_booking_fee', 'fee_terms_note']) && ! $c->isDirty('fee_terms_agreed_at')) {
                $c->fee_terms_agreed_at = now();
            }
        });
        static::saved(fn () => cache()->forget('company.primary'));
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class)->latest('id');
    }

    /** Owners of companies still in onboarding may use the admin panel to finish their profile and add stations. */
    public function canUseAdmin(): bool
    {
        return in_array($this->status, ['active', 'pending', 'changes_requested'], true);
    }

    /** Feature switch from the company's plan (the platform company has everything). */
    /** Whether this company may add another station under its plan's limit. */
    public function canAddStation(): bool
    {
        if ($this->is_platform) {
            return true;
        }
        $limit = ($this->plan ?? Plan::default())?->max_stations;

        return $limit === null || $this->stations()->withTrashed()->count() < $limit;
    }

    /** Commission rates in force: negotiated overrides, else the plan's. */
    public function feeRates(): array
    {
        $plan = $this->plan ?? Plan::default();

        return [
            'commission_percent' => (string) ($this->commission_percent ?? $plan?->commission_percent ?? '0'),
            'per_booking_fee' => (string) ($this->per_booking_fee ?? $plan?->per_booking_fee ?? '0'),
            'negotiated' => $this->commission_percent !== null || $this->per_booking_fee !== null,
        ];
    }

    public static function feeRatesLabel(self $company): string
    {
        $r = $company->feeRates();

        return rtrim(rtrim($r['commission_percent'], '0'), '.').'% of each booking (excl. GST)'
            .((float) $r['per_booking_fee'] > 0 ? ' + ₹'.rtrim(rtrim($r['per_booking_fee'], '0'), '.').' per booking' : '')
            .($r['negotiated'] ? ' · negotiated' : ' · plan rate');
    }

    public function hasFeature(string $feature): bool
    {
        if ($this->is_platform) {
            return true;
        }

        return ($this->plan ?? Plan::default())?->has($feature) ?? false;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasPaymentSetup(): bool
    {
        return ($this->payment_gateway ?: 'sabpaisa') === 'razorpay'
            ? filled($this->razorpay_key_id) && filled($this->razorpay_key_secret)
            : filled($this->sabpaisa_merchant_id) && filled($this->sabpaisa_api_key) && filled($this->sabpaisa_secret_key);
    }

    /** The platform operator (owner of transevrecharge.com): supplies site contact details and the blog. */
    public static function primary(): ?self
    {
        return cache()->remember('company.primary', 300, fn () => static::query()->orderByDesc('is_platform')->orderBy('id')->first());
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Never write payment secrets into the audit log.
        return LogOptions::defaults()->logFillable()->logOnly(['status', 'plan_id', 'is_platform', 'review_note'])->logExcept(['sabpaisa_api_key', 'sabpaisa_secret_key', 'sabpaisa_webhook_secret', 'razorpay_key_secret', 'razorpay_webhook_secret'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }
}

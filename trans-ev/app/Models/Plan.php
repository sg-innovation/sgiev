<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Subscription plan for operators (SRS §22: free basic software, premium features switchable from admin). */
class Plan extends Model
{
    use LogsActivity;

    /** Every switchable feature. Free-plan features follow SRS §22. */
    public const FEATURES = [
        'stations' => 'Station registration & management',
        'chargers' => 'Charger & connector management',
        'live_dashboard' => 'Live station dashboard',
        'online_booking' => 'Customer online booking',
        'walk_in' => 'Walk-in bookings',
        'basic_reports' => 'Basic reports',
        'booking_notifications' => 'Booking notifications',
        'advanced_analytics' => 'Advanced analytics',
        'multi_branch' => 'Multiple branch management',
        'franchise' => 'Join Trans EV franchises (stations in a franchise)',
        'crm' => 'Advanced CRM (coming soon)',
        'white_label' => 'White-label app (coming soon)',
        'advanced_reports' => 'Advanced business reports (PDF/Excel, GST, charger-wise, electricity cost & profit)',
        'accounting_sync' => 'Automated accounting – SGI Accounting (coming soon)',
        'coupons' => 'Coupons & discounts',
        'maintenance' => 'Charger maintenance & work orders',
        'waitlist' => 'Waiting list',
        'reviews' => 'Ratings & reviews (reply to customers)',
        'ocpp' => 'OCPP charger integration & remote control',
    ];

    public const FREE_FEATURES = ['stations', 'chargers', 'live_dashboard', 'online_booking', 'walk_in', 'basic_reports', 'booking_notifications', 'maintenance', 'waitlist', 'reviews'];

    protected $fillable = ['name', 'slug', 'description', 'price_monthly', 'commission_percent', 'per_booking_fee', 'features', 'max_stations', 'is_default', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price_monthly' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

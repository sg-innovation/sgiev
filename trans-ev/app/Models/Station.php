<?php

namespace App\Models;

use App\Enums\StationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Station extends Model
{
    use LogsActivity, SoftDeletes;

    public const TIMEZONE = 'Asia/Kolkata';

    protected $fillable = [
        'company_id', 'name', 'slug', 'code', 'description',
        'address_line1', 'address_line2', 'landmark', 'city', 'state', 'state_code', 'pincode',
        'latitude', 'longitude', 'gstin', 'contact_phone', 'contact_email',
        'is_24x7', 'slot_minutes', 'buffer_minutes', 'amenities', 'photos', 'is_published',
        'parking_available', 'parking_type', 'parking_note', 'allowed_durations',
        'electricity_provider', 'electricity_consumer_no', 'supply_type', 'supply_voltage', 'sanctioned_load_kw',
    ];

    // status / status_reason / status_until are deliberately not fillable:
    // they change only through StationStatusService so every change is logged.

    protected $hidden = ['location'];

    protected function casts(): array
    {
        return [
            'status' => StationStatus::class,
            'status_until' => 'datetime',
            'is_24x7' => 'boolean',
            'is_published' => 'boolean',
            'needs_review' => 'boolean',
            'parking_available' => 'boolean',
            'allowed_durations' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'amenities' => 'array',
            'photos' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Station $station) {
            $station->uuid ??= (string) Str::uuid();
            if ($station->company && ! \App\Support\Tenancy::isPlatformAdmin() && ! $station->company->canAddStation()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Your plan’s station limit is reached. Contact Trans EV to upgrade.']);
            }
            // Stations of the platform company, or created by a platform admin, need no review.
            if (($station->company?->is_platform ?? false) || \App\Support\Tenancy::isPlatformAdmin()) {
                $station->approval_status = 'approved';
                $station->approved_at ??= now();
            }
            $station->slug ??= Str::slug($station->name.'-'.$station->city);
        });

        // Keep the spatial column in step with latitude/longitude.
        // SRID 4326 in MySQL uses latitude-longitude axis order in WKT.
        static::saving(function (Station $station) {
            if ($station->isDirty(['latitude', 'longitude']) || ! $station->exists) {
                $station->setAttribute('location', DB::raw(sprintf(
                    "ST_GeomFromText('POINT(%F %F)', 4326)",
                    (float) $station->latitude,
                    (float) $station->longitude,
                )));
            }
        });

        // Approved stations of partner companies go back to the review queue when legal/location details change.
        static::updating(function (Station $station) {
            if ($station->approval_status === 'approved' && ! ($station->company?->is_platform ?? false)
                && ! \App\Support\Tenancy::isPlatformAdmin()
                && $station->isDirty(['name', 'address_line1', 'city', 'state_code', 'pincode', 'latitude', 'longitude', 'gstin'])) {
                $station->needs_review = true;
            }
        });

        static::saved(function (Station $station) {
            // DB::raw expressions stay on the model after save; drop it so later saves/serialisation are clean.
            unset($station->attributes['location']);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function chargers(): HasMany
    {
        return $this->hasMany(Charger::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class)->orderBy('weekday');
    }

    public function holidayOverrides(): HasMany
    {
        return $this->hasMany(HolidayOverride::class)->orderBy('date');
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    public function ruleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class);
    }

    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable')->latest('created_at');
    }

    /** Visible on the marketplace: published and operated by an active company. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->where('approval_status', 'approved')
            ->whereHas('company', fn (Builder $c) => $c->where('status', 'active'));
    }

    /**
     * Adds `distance_km` and orders by it. A lat/lng bounding box keeps the scan small
     * before the exact spherical distance is computed.
     */
    public function scopeNearest(Builder $query, float $lat, float $lng, ?float $radiusKm = null): Builder
    {
        if ($radiusKm !== null) {
            $latDelta = $radiusKm / 111.045;
            $lngDelta = $radiusKm / (111.045 * max(cos(deg2rad($lat)), 0.01));
            $query->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
                ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta]);
        }

        return $query
            ->select('stations.*')
            ->selectRaw(
                "ROUND(ST_Distance_Sphere(location, ST_GeomFromText(?, 4326)) / 1000, 2) AS distance_km",
                [sprintf('POINT(%F %F)', $lat, $lng)]
            )
            ->when($radiusKm !== null, fn (Builder $q) => $q->havingRaw('distance_km <= ?', [$radiusKm]))
            ->orderBy('distance_km');
    }

    /** Opening window [opens, closes] for a local date, or null when closed that day. */
    public function hoursFor(CarbonInterface $date): ?array
    {
        $local = Carbon::parse($date)->setTimezone(self::TIMEZONE)->startOfDay();

        if ($this->is_24x7) {
            return [$local->copy(), $local->copy()->addDay()];
        }

        $override = $this->relationLoaded('holidayOverrides')
            ? $this->holidayOverrides->first(fn ($h) => $h->date->isSameDay($local))
            : $this->holidayOverrides()->whereDate('date', $local->toDateString())->first();

        $row = $override ?? ($this->relationLoaded('operatingHours')
            ? $this->operatingHours->firstWhere('weekday', $local->dayOfWeek)
            : $this->operatingHours()->where('weekday', $local->dayOfWeek)->first());

        if (! $row || $row->is_closed || ! $row->opens_at || ! $row->closes_at) {
            return null;
        }

        $opens = $local->copy()->setTimeFromTimeString($row->opens_at);
        $closes = $local->copy()->setTimeFromTimeString($row->closes_at);
        if ($closes->lessThanOrEqualTo($opens)) {
            $closes->addDay(); // overnight window, e.g. 18:00–02:00
        }

        return [$opens, $closes];
    }

    public function isOpenAt(?CarbonInterface $at = null): bool
    {
        $at = Carbon::parse($at ?? now())->setTimezone(self::TIMEZONE);

        foreach ([$at->copy()->subDay(), $at] as $day) {
            $window = $this->hoursFor($day);
            if ($window && $at->betweenIncluded($window[0], $window[1])) {
                return true;
            }
        }

        return false;
    }

    public function isBookableNow(): bool
    {
        return $this->status->isBookable() && $this->is_published && ($this->company?->isActive() ?? false) && $this->isOpenAt();
    }

    /** Active tariff for a charger type at a moment (charger-specific tariffs win). */
    public function currentTariff(?string $chargerType = null, ?int $chargerId = null, ?CarbonInterface $at = null): ?Tariff
    {
        $at ??= now();

        return $this->tariffs()
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->where(function ($q) use ($chargerId, $chargerType) {
                $q->when($chargerId, fn ($q) => $q->orWhere('charger_id', $chargerId))
                    ->orWhere(fn ($q) => $q->whereNull('charger_id')->where('charger_type', $chargerType))
                    ->orWhere(fn ($q) => $q->whereNull('charger_id')->whereNull('charger_type'));
            })
            ->orderByRaw('charger_id IS NULL, charger_type IS NULL')
            ->orderByDesc('effective_from')
            ->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class RuleSet extends Model
{
    use LogsActivity;

    protected $fillable = ['company_id', 'station_id', 'version', 'title', 'content', 'created_by'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Rules a customer must accept for a station, most specific first:
     * the station's own rules, then its company's rules, then the platform-wide default.
     */
    public static function activeFor(Station $station): ?self
    {
        return static::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->where('station_id', $station->id)
                ->orWhere(fn ($q) => $q->whereNull('station_id')->where('company_id', $station->company_id))
                ->orWhere(fn ($q) => $q->whereNull('station_id')->whereNull('company_id')))
            ->orderByRaw('station_id IS NULL, company_id IS NULL')
            ->first();
    }

    /** Version numbers count up separately for each scope (platform, company, station). */
    public static function nextVersion(?int $companyId, ?int $stationId): int
    {
        return (int) static::scopeQuery($companyId, $stationId)->max('version') + 1;
    }

    private static function scopeQuery(?int $companyId, ?int $stationId): Builder
    {
        return static::query()
            ->when($stationId, fn ($q) => $q->where('station_id', $stationId), fn ($q) => $q->whereNull('station_id')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id')));
    }

    /** Publishing makes this version the only active one for its scope (customers re-accept on next booking). */
    public function publish(): void
    {
        DB::transaction(function () {
            // Model by model so each deactivation is audit-logged.
            static::scopeQuery($this->company_id, $this->station_id)->whereKeyNot($this->id)->where('is_active', true)
                ->get()->each(fn (RuleSet $old) => $old->forceFill(['is_active' => false])->save());
            $this->forceFill(['is_active' => true, 'published_at' => now()])->save();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

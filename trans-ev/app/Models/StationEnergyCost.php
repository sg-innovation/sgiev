<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Electricity cost of a station for one month (G1 gross-profit estimate). */
class StationEnergyCost extends Model
{
    use LogsActivity;

    protected $fillable = ['company_id', 'station_id', 'month', 'cost_per_kwh', 'fixed_charges', 'units_billed', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['month' => 'date', 'cost_per_kwh' => 'decimal:4', 'fixed_charges' => 'decimal:2', 'units_billed' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::saving(function (StationEnergyCost $c) {
            $c->month = $c->month?->copy()->startOfMonth();
            if ($c->station_id && ! $c->company_id) {
                $c->company_id = Station::query()->whereKey($c->station_id)->value('company_id');
            }
        });
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** One charging session on a connector: who started/stopped it, meter readings and the energy delivered. */
class ChargingSession extends Model
{
    use LogsActivity;

    public const STOP_REASONS = [
        'completed' => 'Charging finished', 'customer' => 'Stopped by the customer', 'slot_end' => 'Slot time ended',
        'fault' => 'Charger fault', 'emergency' => 'Emergency stop', 'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'stopped_at' => 'datetime',
            'meter_start_kwh' => 'decimal:3', 'meter_stop_kwh' => 'decimal:3', 'energy_kwh' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (ChargingSession $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class)->withTrashed();
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class)->withTrashed();
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function stopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by');
    }

    public function minutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->stopped_at ?? now(), true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'meter_start_kwh', 'meter_stop_kwh', 'energy_kwh', 'stop_reason'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

<?php

namespace App\Models;

use App\Enums\ChargerType;
use App\Enums\StationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Charger extends Model
{
    use LogsActivity, SoftDeletes;

    // status / status_reason change only through StationStatusService.
    protected $hidden = ['qr_key'];

    protected $fillable = ['station_id', 'code', 'name', 'oem', 'model', 'serial_no', 'ocpp_identity', 'ocpp_protocol', 'type', 'power_kw', 'connectors',
        'input_voltage_v', 'phases', 'output_voltage_min_v', 'output_voltage_max_v', 'max_current_a', 'standard',
        'meter_serial', 'meter_accuracy_class', 'firmware_version', 'commissioned_on'];

    /** One-line electrical summary for receipts and the station page, e.g. "415 V 3-phase in · 150–1000 V DC out · 188 A max". */
    public function electricalSummary(): ?string
    {
        $parts = [];
        if ($this->input_voltage_v) {
            $parts[] = $this->input_voltage_v.' V'.($this->phases ? ' '.$this->phases.'-phase' : '').($this->type === ChargerType::DC ? ' in' : '');
        }
        if ($this->type === ChargerType::DC && $this->output_voltage_max_v) {
            $parts[] = ($this->output_voltage_min_v ? $this->output_voltage_min_v.'–' : 'up to ').$this->output_voltage_max_v.' V DC out';
        }
        if ($this->max_current_a) {
            $parts[] = rtrim(rtrim((string) $this->max_current_a, '0'), '.').' A max';
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    protected function casts(): array
    {
        return [
            'type' => ChargerType::class,
            'commissioned_on' => 'date',
            'max_current_a' => 'decimal:1',
            'status' => StationStatus::class,
            'connectors' => 'array',
            'power_kw' => 'decimal:2',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Charger $c) => $c->qr_key ??= \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(24)));
        // Chargers created with a list of connector types (seeders, imports) get one connector row per type.
        static::created(function (Charger $charger) {
            if ($charger->connectorPoints()->withTrashed()->doesntExist()) {
                foreach (array_values($charger->connectors ?? []) as $i => $type) {
                    $charger->connectorPoints()->create(['connector_no' => $i + 1, 'type' => $type]);
                }
            }
        });
    }

    /** Bookable connectors (guns / sockets). */
    public function connectorPoints(): HasMany
    {
        return $this->hasMany(Connector::class)->orderBy('connector_no');
    }

    /** Keep the `connectors` type summary (used by search filters) equal to the live connector rows. */
    public function syncConnectorTypes(): void
    {
        $types = $this->connectorPoints()->get()->map(fn (Connector $c) => $c->type->value)->unique()->values()->all();
        if ($types !== array_values($this->connectors ?? [])) {
            $this->forceFill(['connectors' => $types])->saveQuietly();
        }
    }

    /** OCPP link state for the admin (manual = no hardware integration). */
    public function ocppState(): string
    {
        if (! $this->ocpp_identity) {
            return 'manual';
        }

        return $this->last_heartbeat_at?->gt(now()->subMinutes(10)) ? 'connected' : 'disconnected';
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable')->latest('created_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

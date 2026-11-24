<?php

namespace App\Models;

use App\Enums\ConnectorLiveStatus;
use App\Enums\ConnectorType;
use App\Enums\StationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A connector (gun / socket) on a charger. The unit customers actually book. */
class Connector extends Model
{
    use LogsActivity, SoftDeletes;

    // status and live_status change only through ConnectorStatusService (logged).
    protected $fillable = ['charger_id', 'connector_no', 'type', 'max_kw'];

    protected function casts(): array
    {
        return [
            'type' => ConnectorType::class,
            'status' => StationStatus::class,
            'live_status' => ConnectorLiveStatus::class,
            'live_status_at' => 'datetime',
            'max_kw' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Connector $c) {
            $c->connector_no ??= (int) static::withTrashed()->where('charger_id', $c->charger_id)->max('connector_no') + 1;
        });
        // Keep the charger's connector-type summary (used by search filters) in step.
        $sync = fn (Connector $c) => $c->charger?->syncConnectorTypes();
        static::saved($sync);
        static::deleted($sync);
        static::restored($sync);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class)->withTrashed();
    }

    public function history(): HasMany
    {
        return $this->hasMany(ConnectorStatusHistory::class)->latest('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Effective power: the connector limit, or the charger's rated power. */
    public function powerKw(): string
    {
        return (string) ($this->max_kw ?? $this->charger->power_kw);
    }

    /** Can be booked at all (ignoring time and existing bookings). */
    public function isBookable(): bool
    {
        return ! $this->trashed()
            && $this->status === StationStatus::Online
            && ! $this->live_status->isDown()
            && $this->charger && ! $this->charger->trashed() && $this->charger->status === StationStatus::Online;
    }

    public function label(): string
    {
        return "{$this->charger->code} #{$this->connector_no} · {$this->type->getLabel()}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'max_kw', 'connector_no'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

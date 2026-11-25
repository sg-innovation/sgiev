<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Maintenance work order on a charger (fault, repair, preventive service, inspection, meter calibration). */
class ChargerWorkOrder extends Model
{
    use LogsActivity;

    public const TYPES = ['fault' => 'Fault', 'repair' => 'Repair', 'preventive' => 'Preventive service', 'inspection' => 'Inspection', 'calibration' => 'Meter calibration', 'other' => 'Other'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    public const ACTIVE = ['open', 'in_progress', 'on_hold'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'took_offline' => 'boolean', 'opened_at' => 'datetime', 'due_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'labour_cost' => 'decimal:2', 'parts_cost' => 'decimal:2', 'attachments' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ChargerWorkOrder $w) {
            $w->uuid ??= (string) Str::uuid();
            $w->opened_at ??= now();
            if ($w->charger_id && (! $w->station_id || ! $w->company_id)) {
                $charger = Charger::query()->with('station:id,company_id')->find($w->charger_id);
                $w->station_id ??= $charger?->station_id;
                $w->company_id ??= $charger?->station?->company_id;
            }
        });
        static::created(fn (ChargerWorkOrder $w) => $w->forceFill(['number' => 'WO-'.str_pad((string) $w->id, 6, '0', STR_PAD_LEFT)])->saveQuietly());
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function totalCost(): string
    {
        return bcadd((string) ($this->labour_cost ?? '0'), (string) ($this->parts_cost ?? '0'), 2);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'priority', 'assigned_to', 'took_offline', 'resolution', 'labour_cost', 'parts_cost'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

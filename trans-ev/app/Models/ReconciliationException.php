<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A mismatch found by reconciliation, worked through the exceptions queue (open → resolved / ignored). */
class ReconciliationException extends Model
{
    use LogsActivity;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const IGNORED = 'ignored';

    public const STATUSES = [self::OPEN => 'Open', self::RESOLVED => 'Resolved', self::IGNORED => 'Ignored'];

    public const SEVERITIES = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array', 'expected_amount' => 'decimal:2', 'actual_amount' => 'decimal:2',
            'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function typeLabel(): string
    {
        return \App\Services\ReconciliationService::TYPES[$this->type]['label'] ?? $this->type;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'assigned_to', 'resolution_note'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

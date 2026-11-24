<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Ticket extends Model
{
    use LogsActivity;

    /** Workflow states (the logic depends on them, so they are fixed; their labels are shown here). */
    public const STATUSES = [
        'open' => 'Open', 'in_progress' => 'In progress', 'waiting_on_customer' => 'Waiting on requester',
        'resolved' => 'Resolved', 'closed' => 'Closed',
    ];

    public const ACTIVE = ['open', 'in_progress', 'waiting_on_customer'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Ticket $t) => $t->uuid ??= (string) Str::uuid());
        static::created(fn (Ticket $t) => $t->forceFill(['number' => 'TKT-'.str_pad((string) $t->id, 6, '0', STR_PAD_LEFT)])->saveQuietly());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requesterCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'requester_company_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** First-response or resolution SLA breached (or about to be). */
    public function slaState(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }
        $due = $this->first_responded_at ? $this->resolution_due_at : $this->first_response_due_at;
        if (! $due) {
            return null;
        }

        return $due->isPast() ? 'overdue' : ($due->diffInMinutes(now()) < 60 ? 'due_soon' : 'on_track');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'assigned_to', 'priority_id', 'category_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

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
            'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'sla_paused_at' => 'datetime', 'sla_warned_at' => 'datetime',
            'first_response_breached_at' => 'datetime', 'resolution_breached_at' => 'datetime', 'escalated_at' => 'datetime', 'first_responded_at' => 'datetime',
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

    /** Raised from the contact form by someone without an account. */
    public function isGuest(): bool
    {
        return $this->requester_id === null && filled($this->guest_email);
    }

    public function requesterName(): string
    {
        return $this->requester?->name ?? $this->guest_name ?? 'Guest';
    }

    public function requesterEmail(): ?string
    {
        return $this->requester?->email ?? $this->guest_email;
    }

    public function requesterPhone(): ?string
    {
        return $this->requester?->phone ?? $this->guest_phone;
    }

    /** Whether a message was written by the requester (account holder or guest). */
    public function isFromRequester(TicketMessage $m): bool
    {
        return $m->event === null && ($this->requester_id !== null ? $m->user_id === $this->requester_id : $m->user_id === null);
    }

    /** Private link for a guest to read and reply (signed; bump guest_link_version to cancel old links). */
    public function guestUrl(string $route = 'support.guest.show', array $extra = []): string
    {
        return \Illuminate\Support\Facades\URL::signedRoute($route, ['ticket' => $this->uuid, 'v' => $this->guest_link_version ?? 1] + $extra);
    }

    /** Who to notify as the requester: the account, or the guest's email address. */
    public function requesterNotifiable(): ?object
    {
        if ($this->requester) {
            return $this->requester;
        }

        return $this->guest_email ? \Illuminate\Support\Facades\Notification::route('mail', [$this->guest_email => $this->guest_name]) : null;
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

    /** breached / overdue / due_soon / on_track / paused (waiting on requester), or null when closed. */
    public function slaState(): ?string
    {
        if (! $this->isActive()) {
            return $this->first_response_breached_at || $this->resolution_breached_at ? 'breached' : null;
        }
        if ($this->sla_paused_at) {
            return 'paused';
        }
        $due = $this->first_responded_at ? $this->resolution_due_at : $this->first_response_due_at;
        if (! $due) {
            return null;
        }
        if ($due->isPast()) {
            return $this->first_response_breached_at || $this->resolution_breached_at ? 'breached' : 'overdue';
        }

        return $due->diffInMinutes(now(), true) < max(1, \App\Support\BusinessRules::int('tickets.sla_warning_minutes')) ? 'due_soon' : 'on_track';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'assigned_to', 'priority_id', 'category_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

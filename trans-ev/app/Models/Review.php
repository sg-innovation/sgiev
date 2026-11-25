<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A customer's rating and review of a station (and its charger) for one completed booking. */
class Review extends Model
{
    use LogsActivity;

    public const STATUSES = ['pending' => 'Waiting for approval', 'published' => 'Published', 'rejected' => 'Not published'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'moderated_at' => 'datetime', 'owner_replied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Review $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class)->withTrashed();
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_replied_by');
    }

    /** "Asha R." — reviews never show full names, emails or phone numbers. */
    public function displayName(): string
    {
        $parts = preg_split('/\s+/', trim((string) ($this->user?->name ?? 'Customer')));

        return $parts[0].(isset($parts[1]) ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : '');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'station_rating', 'charger_rating', 'owner_reply', 'moderation_note'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

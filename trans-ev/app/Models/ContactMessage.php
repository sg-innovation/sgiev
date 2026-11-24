<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ContactMessage extends Model
{
    use LogsActivity;

    /** Contact-form topics (Admin → Settings → Lists & options → Contact-form topics). */
    public static function topics(): array
    {
        return \App\Models\Lookup::options('contact_topic');
    }

    public const STATUSES = [
        'new' => 'New',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'spam' => 'Spam',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ContactMessage $m) {
            do {
                $m->reference = 'TEV-Q'.now(Station::TIMEZONE)->format('ymd').'-'.Str::upper(Str::random(4));
            } while (static::query()->where('reference', $m->reference)->exists());
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'assigned_to', 'internal_notes'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

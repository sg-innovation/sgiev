<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Someone (e.g. the Flutter developer) allowed into the developer portal with a 6-digit PIN (stored hashed). */
class ApiDeveloper extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'locked_until' => 'datetime'];
    }

    public function canEnter(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture()) && ($this->locked_until === null || $this->locked_until->isPast());
    }

    public function testUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'test_user_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'email', 'company', 'is_active', 'expires_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

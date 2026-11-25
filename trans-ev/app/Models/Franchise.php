<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * G3: a franchise is a regional partner of Trans EV over a group of stations (from one or more operators). Its regional
 * managers see those stations' operations and reports; it earns a commission on their net sales, paid monthly.
 */
class Franchise extends Model
{
    use LogsActivity;

    public const STATUSES = ['pending' => 'Applied', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:2', 'bank_details' => 'array', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Franchise $f) => $f->code ??= 'TMP-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12)));
        static::created(function (Franchise $f) {
            if (str_starts_with((string) $f->code, 'TMP-')) {
                $f->forceFill(['code' => 'FR-'.str_pad((string) $f->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class)->withPivot('joined_on')->withTimestamps();
    }

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(FranchisePayout::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'status', 'commission_percent', 'region', 'review_note'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

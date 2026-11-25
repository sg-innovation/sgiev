<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** What a ticket is about, who can choose it, and who resolves it (managed in admin). */
class TicketCategory extends Model
{
    use LogsActivity;

    public const AUDIENCES = ['all' => 'Everyone', 'customer' => 'Customers', 'partner' => 'Partners & staff'];

    public const HANDLERS = ['platform' => 'Trans EV support', 'operator' => 'Operator of the booked station'];

    protected $fillable = ['name', 'slug', 'description', 'audience', 'handled_by', 'requires_booking', 'default_priority_id', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['requires_booking' => 'boolean', 'is_active' => 'boolean'];
    }

    public function defaultPriority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'default_priority_id');
    }

    /** Categories a given user may pick when raising a ticket. */
    public function scopeFor(Builder $query, ?User $user): Builder
    {
        $audience = $user?->hasAnyRole(\App\Enums\Role::staff()) ? 'partner' : 'customer';

        return $query->where('is_active', true)->whereIn('audience', ['all', $audience])->orderBy('sort')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

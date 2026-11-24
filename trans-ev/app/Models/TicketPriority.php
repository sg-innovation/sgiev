<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Ticket priority with its SLA targets (managed in admin). */
class TicketPriority extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'slug', 'color', 'first_response_minutes', 'resolution_minutes', 'customer_selectable', 'is_default', 'sort'];

    protected function casts(): array
    {
        return ['customer_selectable' => 'boolean', 'is_default' => 'boolean'];
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->orderBy('sort')->first();
    }

    public static function humanMinutes(int $m): string
    {
        return match (true) {
            $m < 60 => "{$m} min",
            $m % 1440 === 0 => ($m / 1440).' '.str('day')->plural($m / 1440),
            $m % 60 === 0 => ($m / 60).' h',
            default => intdiv($m, 60).' h '.($m % 60).' min',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

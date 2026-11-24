<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** One editable block of website copy (see App\Support\SiteContent). */
class SiteSection extends Model
{
    use LogsActivity;

    protected $fillable = ['key', 'data', 'updated_by'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\SiteContent::flush());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['key', 'data'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

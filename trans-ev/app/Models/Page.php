<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A website page managed in admin. Every published change is kept in page_versions. */
class Page extends Model
{
    use LogsActivity;

    protected $fillable = ['slug', 'title', 'intro', 'body', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Snapshot each new version (append-only history).
        static::saved(function (Page $page) {
            if ($page->wasRecentlyCreated || $page->wasChanged(['title', 'intro', 'body'])) {
                $page->versions()->create([
                    'version' => $page->version, 'title' => $page->title, 'intro' => $page->intro, 'body' => $page->body,
                    'change_note' => $page->changeNote ?? null, 'created_by' => auth()->id(),
                ]);
            }
        });
        static::updating(function (Page $page) {
            if ($page->isDirty(['title', 'intro', 'body'])) {
                $page->version = ($page->getOriginal('version') ?? 0) + 1;
                $page->published_at = now();
                $page->updated_by = auth()->id();
            }
        });
        static::creating(function (Page $page) {
            $page->version ??= 1;
            $page->published_at ??= now();
            $page->updated_by ??= auth()->id();
        });
    }

    /** Optional note recorded with the next version (not a column). */
    public ?string $changeNote = null;

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderByDesc('version');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'intro', 'is_published', 'version'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

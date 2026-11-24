<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BlogPost extends Model
{
    use LogsActivity, SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'pending' => 'Pending review',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'archived' => 'Archived',
    ];

    protected $fillable = [
        'title', 'slug', 'excerpt', 'content', 'cover_image', 'cover_alt', 'blog_category_id', 'author_id',
        'status', 'published_at', 'is_featured', 'allow_indexing', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'allow_indexing' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BlogPost $p) {
            $p->uuid ??= (string) Str::uuid();
            $p->slug = $p->slug ?: Str::slug($p->title);
        });

        static::saving(function (BlogPost $p) {
            $words = str_word_count(strip_tags((string) $p->content));
            $p->reading_minutes = max(1, (int) ceil($words / 200));

            // A "published" post with a future date is scheduled; a scheduled post whose time has come is live.
            if ($p->status === 'published' && $p->published_at?->isFuture()) {
                $p->status = 'scheduled';
            }
            if (in_array($p->status, ['published', 'scheduled'], true) && ! $p->published_at) {
                $p->published_at = now();
            }
        });

        static::saved(function (BlogPost $p) {
            if ($p->wasRecentlyCreated || $p->wasChanged(['title', 'excerpt', 'content'])) {
                $p->revisions()->create([
                    'user_id' => auth()->id() ?? $p->author_id,
                    'title' => $p->title,
                    'excerpt' => $p->excerpt,
                    'content' => $p->content,
                ]);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BlogPostRevision::class)->latest('id');
    }

    /** Publicly visible: published (or scheduled and due) and not in the future. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereIn('status', ['published', 'scheduled'])->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['published', 'scheduled'], true) && $this->published_at && ! $this->published_at->isFuture();
    }

    public function coverUrl(): ?string
    {
        if (! $this->cover_image) {
            return null;
        }

        return Str::startsWith($this->cover_image, ['http://', 'https://', '/']) ? $this->cover_image : Storage::disk('public')->url($this->cover_image);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'slug', 'status', 'published_at', 'is_featured', 'blog_category_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}

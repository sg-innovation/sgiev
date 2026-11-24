<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BlogCategory extends Model
{
    public const COLORS = ['emerald' => 'Green', 'sky' => 'Blue', 'violet' => 'Violet', 'amber' => 'Amber', 'rose' => 'Rose', 'teal' => 'Teal'];

    protected $fillable = ['name', 'slug', 'description', 'color', 'sort'];

    protected static function booted(): void
    {
        static::creating(fn (BlogCategory $c) => $c->slug = $c->slug ?: Str::slug($c->name));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class);
    }
}

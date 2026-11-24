<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** One option in an admin-managed list (see TYPES). */
class Lookup extends Model
{
    use LogsActivity;

    /** List types and the extra fields each one uses. */
    public const TYPES = [
        'kyc_document' => ['label' => 'KYC document types', 'meta' => ['required' => 'Required for approval', 'on_registration' => 'Asked on the partner sign-up form']],
        'amenity' => ['label' => 'Station amenities', 'meta' => []],
        'parking_type' => ['label' => 'Parking types', 'meta' => []],
        'contact_topic' => ['label' => 'Contact-form topics', 'meta' => []],
        'ev_model' => ['label' => 'Popular EV models (booking shortcuts)', 'meta' => ['make' => 'Make', 'model' => 'Model', 'battery_kwh' => 'Battery (kWh)', 'connector' => 'Connector']],
    ];

    protected $fillable = ['type', 'value', 'label', 'description', 'meta', 'sort', 'is_active'];

    /** @var array<string, Collection> per-request cache */
    private static array $cache = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$cache = []);
        static::deleted(fn () => self::$cache = []);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->where('is_active', true)->orderBy('sort')->orderBy('label');
    }

    /** Active options of a type. */
    public static function list(string $type): Collection
    {
        return self::$cache[$type] ??= static::query()->ofType($type)->get();
    }

    /** value => label for selects. Includes inactive values when $withInactive (to label old records). */
    public static function options(string $type, bool $withInactive = false): array
    {
        if ($withInactive) {
            return static::query()->where('type', $type)->orderBy('sort')->pluck('label', 'value')->all();
        }

        return self::list($type)->pluck('label', 'value')->all();
    }

    public static function label(string $type, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::options($type, true)[$value] ?? $value;
    }

    /** Values of a type whose meta flag is true. */
    public static function flagged(string $type, string $flag): array
    {
        return self::list($type)->filter(fn (Lookup $l) => (bool) ($l->meta[$flag] ?? false))->pluck('value')->values()->all();
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

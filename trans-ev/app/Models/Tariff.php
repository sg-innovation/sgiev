<?php

namespace App\Models;

use App\Enums\ChargerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Tariff extends Model
{
    use LogsActivity;

    protected $fillable = [
        'station_id', 'charger_id', 'charger_type', 'name',
        'rate_per_kwh', 'time_bands', 'session_fee', 'parking_fee', 'parking_fee_per_hour', 'idle_fee_per_min', 'idle_grace_minutes',
        'gst_rate', 'sac_code', 'parking_sac', 'effective_from', 'effective_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'charger_type' => ChargerType::class,
            'rate_per_kwh' => 'decimal:2',
            'time_bands' => 'array',
            'parking_fee' => 'decimal:2',
            'parking_fee_per_hour' => 'decimal:2',
            'session_fee' => 'decimal:2',
            'idle_fee_per_min' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }

    public function isCurrent(): bool
    {
        return $this->effective_from->isPast() && ($this->effective_to === null || $this->effective_to->isFuture());
    }

    /** Rate including GST, for display only. Invoices compute tax per line in GstService (Phase 4). */
    public function ratePerKwhInclGst(): string
    {
        return bcmul((string) $this->rate_per_kwh, bcadd('1', bcdiv((string) $this->gst_rate, '100', 6), 6), 2);
    }

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];

    public function hasTimeBands(): bool
    {
        return filled($this->time_bands);
    }

    /** The band (or null for the standard rate) that applies at a local time. */
    public function bandAt(\Carbon\CarbonInterface $local): ?array
    {
        $minute = $local->hour * 60 + $local->minute;
        foreach ($this->time_bands ?? [] as $band) {
            [$fh, $fm] = array_map('intval', explode(':', (string) ($band['from'] ?? '00:00')) + [0, 0]);
            [$th, $tm] = array_map('intval', explode(':', (string) ($band['to'] ?? '00:00')) + [0, 0]);
            $from = $fh * 60 + $fm;
            $to = $th * 60 + $tm;
            $days = array_map('intval', $band['days'] ?? []);
            if ($from === $to) {
                continue;
            }
            // A band that crosses midnight belongs to the day it starts on.
            $inWindow = $from < $to ? ($minute >= $from && $minute < $to) : ($minute >= $from || $minute < $to);
            $day = ($from > $to && $minute < $to) ? ($local->dayOfWeek + 6) % 7 : $local->dayOfWeek;
            if ($inWindow && ($days === [] || in_array($day, $days, true))) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Split a slot into rate segments: [{name, rate, minutes}] in time order (consecutive minutes at the same rate merged).
     *
     * @return list<array{name: string, rate: string, minutes: int}>
     */
    public function segments(\Carbon\CarbonInterface $start, int $minutes): array
    {
        $out = [];
        $local = $start->copy()->setTimezone(Station::TIMEZONE);
        for ($i = 0; $i < $minutes; $i++) {
            $band = $this->hasTimeBands() ? $this->bandAt($local->copy()->addMinutes($i)) : null;
            $name = $band['name'] ?? 'Standard';
            $rate = number_format((float) ($band['rate_per_kwh'] ?? $this->rate_per_kwh), 2, '.', '');
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['name'] === $name && $out[$last]['rate'] === $rate) {
                $out[$last]['minutes']++;
            } else {
                $out[] = ['name' => $name, 'rate' => $rate, 'minutes' => 1];
            }
        }

        return $out;
    }

    /** Human description of the bands, e.g. "Peak Mon–Fri 18:00–22:00 ₹24/kWh". */
    public function bandsSummary(): array
    {
        return collect($this->time_bands ?? [])->map(function ($b) {
            $days = array_map('intval', $b['days'] ?? []);
            $label = $days === [] || count($days) === 7 ? 'every day' : collect(self::DAYS)->only($days)->implode(', ');

            return ['name' => $b['name'] ?? 'Band', 'days' => $label, 'from' => $b['from'] ?? '', 'to' => $b['to'] ?? '', 'rate' => (float) ($b['rate_per_kwh'] ?? 0)];
        })->values()->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

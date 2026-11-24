<?php

namespace App\Models;

use App\Services\GstService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * How much a customer gets back when they cancel, and on a no-show.
 * Station policy → operator policy → Trans EV default (company_id and station_id null).
 */
class CancellationPolicy extends Model
{
    use LogsActivity;

    protected $fillable = ['company_id', 'station_id', 'name', 'tiers', 'no_show_refund_percent', 'customer_note', 'is_active'];

    protected function casts(): array
    {
        return ['tiers' => 'array', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (CancellationPolicy $p) {
            if ($p->isDirty(['tiers', 'no_show_refund_percent', 'customer_note'])) {
                $p->version = ($p->getOriginal('version') ?? 1) + 1;
            }
            $p->updated_by = auth()->id() ?? $p->updated_by;
        });
        static::creating(fn (CancellationPolicy $p) => $p->updated_by ??= auth()->id());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public static function platformDefault(): ?self
    {
        return static::query()->whereNull('company_id')->whereNull('station_id')->where('is_active', true)->latest('id')->first();
    }

    /** The policy that applies to new bookings at this station. */
    public static function activeFor(Station $station): ?self
    {
        return static::query()->where('is_active', true)->where('station_id', $station->id)->latest('id')->first()
            ?? static::query()->where('is_active', true)->where('company_id', $station->company_id)->whereNull('station_id')->latest('id')->first()
            ?? static::platformDefault();
    }

    /** Tiers sorted from earliest cancellation to latest. */
    public function sortedTiers(): array
    {
        return self::normaliseTiers($this->tiers ?? []);
    }

    public static function normaliseTiers(array $tiers): array
    {
        return collect($tiers)->map(fn ($t) => [
            'min_minutes_before' => (int) ($t['min_minutes_before'] ?? 0),
            'refund_percent' => max(0, min(100, (int) ($t['refund_percent'] ?? 0))),
            'fee' => number_format(max(0, (float) ($t['fee'] ?? 0)), 2, '.', ''),
            'label' => trim(strip_tags((string) ($t['label'] ?? ''))),
        ])->sortByDesc('min_minutes_before')->values()->all();
    }

    /** What is stored on a booking (and shown to the customer) when they reserve. */
    public function snapshot(): array
    {
        return [
            'id' => $this->id, 'version' => $this->version, 'name' => $this->name,
            'tiers' => $this->sortedTiers(), 'no_show_refund_percent' => (int) $this->no_show_refund_percent,
            'customer_note' => $this->customer_note,
        ];
    }

    /**
     * Refund for a cancellation N minutes before the start, per a policy snapshot.
     *
     * @return array{refund: string, percent: int, fee: string, rule: string}
     */
    public static function quote(array $snapshot, string $paid, int $minutesBefore): array
    {
        $tier = collect($snapshot['tiers'] ?? [])->sortByDesc('min_minutes_before')->first(fn ($t) => $minutesBefore >= (int) $t['min_minutes_before'])
            ?? ['refund_percent' => 0, 'fee' => '0', 'label' => 'No refund (the slot has started)'];
        $refund = GstService::round(bcdiv(bcmul($paid, (string) $tier['refund_percent'], 4), '100', 4));
        $fee = (string) ($tier['fee'] ?? '0');
        if (bccomp($fee, '0', 2) > 0) {
            $refund = bccomp($refund, $fee, 2) > 0 ? bcsub($refund, $fee, 2) : '0.00';
        }

        return ['refund' => $refund, 'percent' => (int) $tier['refund_percent'], 'fee' => $fee, 'rule' => $tier['label'] ?: ($tier['refund_percent'].'% refund')];
    }

    /** One-line summary for lists. */
    public function summary(): string
    {
        return collect($this->sortedTiers())->map(fn ($t) => ($t['min_minutes_before'] > 0 ? '≥'.self::minutes($t['min_minutes_before']).': ' : 'later: ').$t['refund_percent'].'%'
            .((float) $t['fee'] > 0 ? ' − ₹'.rtrim(rtrim($t['fee'], '0'), '.') : ''))->implode(' · ').' · no-show '.$this->no_show_refund_percent.'%';
    }

    public static function minutes(int $m): string
    {
        return $m >= 1440 && $m % 1440 === 0 ? ($m / 1440).' d' : ($m >= 60 && $m % 60 === 0 ? ($m / 60).' h' : $m.' min');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}

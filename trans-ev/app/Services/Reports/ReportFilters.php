<?php

namespace App\Services\Reports;

use App\Models\Station;
use App\Models\User;
use App\Services\ChargingSessionService;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;

/**
 * What a report covers: IST date range, grouping, operator and stations. Built from the request but always narrowed
 * to what the user may see (operator staff: own company; station managers: assigned stations).
 */
final class ReportFilters
{
    public const TZ = 'Asia/Kolkata';

    public const GROUPS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    /** @param list<int>|null $stationIds null = every station in scope */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $group = 'day',
        public readonly ?int $companyId = null,
        public readonly ?array $stationIds = null,
        public readonly ?int $franchiseId = null,
    ) {}

    public static function forUser(User $user, array $input = []): self
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $from = self::date($input['from'] ?? null) ?? $today->startOfMonth();
        $to = self::date($input['to'] ?? null) ?? $today;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 3 * 366) {
            $from = $to->subYears(3)->addDay();
        }
        $group = array_key_exists($input['group'] ?? '', self::GROUPS) ? $input['group'] : 'day';
        if ($group === 'day' && $from->diffInDays($to) > 92) {
            $group = 'month';
        }

        // Operator: Trans EV staff may pick one (or all); everyone else is fixed to their company. Regional managers
        // work across operators but only on their franchise's stations.
        $regional = \App\Services\FranchiseService::isRegionalOnly($user);
        $own = $regional ? null : Tenancy::companyId($user);
        $companyId = $own ?? (! $regional && filled($input['company'] ?? null) ? (int) $input['company'] : null);

        // Stations: limited to what the user works at; a request outside that is ignored.
        $allowed = ChargingSessionService::stations($user)->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $restricted = $regional || ($own !== null && ! $user->hasAnyRole(\App\Enums\Role::companyWide()));
        $wanted = array_values(array_filter(array_map('intval', (array) ($input['stations'] ?? []))));

        // Franchise: its stations (still within what the user may see).
        $franchiseId = filled($input['franchise'] ?? null) ? (int) $input['franchise'] : null;
        if ($franchiseId) {
            $franchiseStations = \Illuminate\Support\Facades\DB::table('franchise_station')->where('franchise_id', $franchiseId)->pluck('station_id')->map(fn ($id) => (int) $id)->all();
            $wanted = $wanted ? array_values(array_intersect($wanted, $franchiseStations)) : $franchiseStations;
            $wanted = $wanted ?: [0];
        }
        $stationIds = null;
        if ($wanted) {
            $stationIds = (clone $allowed)->whereIn('id', $wanted)->pluck('id')->all() ?: [0];
        } elseif ($restricted) {
            $stationIds = (clone $allowed)->pluck('id')->all() ?: [0];
        }

        return new self($from, $to, $group, $companyId, $stationIds, $franchiseId);
    }

    private static function date(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $v, self::TZ);
        } catch (\Throwable) {
            return null;
        }
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->endOfDay()->utc();
    }

    public function label(): string
    {
        return $this->from->format('j M Y').' – '.$this->to->format('j M Y');
    }

    /** Human description of the scope, for report headers. */
    public function scopeLabel(): string
    {
        $company = $this->franchiseId ? 'Franchise '.\App\Models\Franchise::query()->whereKey($this->franchiseId)->value('name')
            : ($this->companyId ? \App\Models\Company::query()->whereKey($this->companyId)->value('trade_name') : 'All operators');
        if ($this->stationIds === null) {
            return $company.' · all stations';
        }
        $names = Station::query()->whereIn('id', $this->stationIds)->orderBy('name')->pluck('name');

        return $company.' · '.($names->count() > 3 ? $names->count().' stations' : ($names->implode(', ') ?: 'no stations'));
    }

    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'group' => $this->group, 'company' => $this->companyId, 'stations' => $this->stationIds, 'franchise' => $this->franchiseId];
    }
}

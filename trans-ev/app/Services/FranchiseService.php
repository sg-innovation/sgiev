<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Franchise;
use App\Models\FranchisePayout;
use App\Models\Station;
use App\Models\User;
use App\Notifications\FranchiseUpdate;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * G3 franchises: applications and approval, stations and regional managers, and monthly commission payouts
 * (net sales excl. GST of the franchise's stations, from the same figures as Business reports).
 */
class FranchiseService
{
    public function __construct(private readonly ReportService $reports) {}

    /** Public "Become a franchise partner" application. */
    public function apply(array $data): Franchise
    {
        $f = Franchise::create([
            'name' => $data['name'], 'legal_name' => $data['legal_name'] ?? null, 'contact_name' => $data['contact_name'],
            'email' => strtolower($data['email']), 'phone' => $data['phone'] ?? null, 'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null, 'region' => $data['region'] ?? null, 'gstin' => $data['gstin'] ?? null,
            'application_note' => $data['message'] ?? null, 'status' => 'pending',
        ]);
        $this->platformTeam()->each(fn (User $u) => $u->notify(new FranchiseUpdate($f->fresh(), 'franchise.application')));

        return $f->fresh();
    }

    public function approve(Franchise $f, User $by, string $commissionPercent, ?string $note = null): void
    {
        $f->forceFill(['status' => 'active', 'commission_percent' => $commissionPercent, 'review_note' => $note, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        \Illuminate\Support\Facades\Notification::route('mail', [$f->email => $f->contact_name])->notify(new FranchiseUpdate($f->fresh(), 'franchise.approved'));
    }

    public function reject(Franchise $f, User $by, string $reason): void
    {
        $f->forceFill(['status' => 'rejected', 'review_note' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        \Illuminate\Support\Facades\Notification::route('mail', [$f->email => $f->contact_name])->notify(new FranchiseUpdate($f->fresh(), 'franchise.rejected'));
    }

    public function setStatus(Franchise $f, string $status, User $by, ?string $note = null): void
    {
        abort_unless(array_key_exists($status, Franchise::STATUSES), 422);
        $f->forceFill(['status' => $status, 'review_note' => $note ?? $f->review_note, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
    }

    /** @param list<int> $stationIds */
    public function syncStations(Franchise $f, array $stationIds): void
    {
        $taken = DB::table('franchise_station')->whereIn('station_id', $stationIds)->where('franchise_id', '<>', $f->id)->pluck('station_id');
        if ($taken->isNotEmpty()) {
            $names = Station::query()->whereIn('id', $taken)->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['stations' => "Already in another franchise: {$names}."]);
        }
        $notAllowed = Station::query()->with('company')->whereIn('id', $stationIds)->get()->reject(fn (Station $s) => $s->company?->hasFeature('franchise') ?? false);
        if ($notAllowed->isNotEmpty()) {
            throw ValidationException::withMessages(['stations' => 'Their operator’s plan does not include franchises: '.$notAllowed->pluck('name')->implode(', ').'.']);
        }
        $f->stations()->sync(collect($stationIds)->mapWithKeys(fn ($id) => [$id => ['joined_on' => now()->toDateString()]])->all());
    }

    /** @param list<int> $userIds regional managers (given the Regional manager role) */
    public function syncManagers(Franchise $f, array $userIds): void
    {
        $f->managers()->sync($userIds);
        User::query()->whereIn('id', $userIds)->get()->each(function (User $u) {
            if (! $u->hasRole(Role::RegionalManager->value) && ! $u->hasRole(Role::SuperAdmin->value)) {
                $u->assignRole(Role::RegionalManager->value);
            }
        });
    }

    /** Stations a regional manager may see: those of their active franchises. */
    public static function stationIdsFor(User $user): array
    {
        return DB::table('franchise_station as fs')->join('franchise_user as fu', 'fu.franchise_id', '=', 'fs.franchise_id')
            ->join('franchises as f', 'f.id', '=', 'fs.franchise_id')->where('fu.user_id', $user->id)->where('f.status', 'active')
            ->pluck('fs.station_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function isRegionalOnly(User $user): bool
    {
        return $user->hasRole(Role::RegionalManager->value) && ! $user->hasAnyRole(Role::companyWide()) && ! $user->hasRole(Role::StationManager->value);
    }

    /**
     * Draft (or refresh a draft) commission payout for one month. Approved or paid payouts are never changed.
     */
    public function generatePayout(Franchise $f, CarbonImmutable $month): ?FranchisePayout
    {
        $start = $month->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $existing = FranchisePayout::query()->where('franchise_id', $f->id)->whereDate('period_start', $start->toDateString())->first();
        if ($existing && $existing->status !== 'draft') {
            return $existing;
        }
        $stationIds = $f->stations()->pluck('stations.id')->all();
        $filters = new ReportFilters($start, $end, 'month', null, $stationIds ?: [0]);
        $byStation = $this->reports->breakdown($filters, 'station');
        $percent = (string) $f->commission_percent;
        $lines = collect($byStation['rows'])->map(fn ($r) => [
            'station_id' => (int) $r['key'], 'station' => $r['label'], 'sessions' => $r['sessions'], 'kwh' => $r['kwh'], 'net_sales' => $r['sales'],
            'commission' => bcdiv(bcmul((string) $r['sales'], $percent, 4), '100', 2),
        ])->values()->all();
        $netSales = $byStation['totals']['sales'] ?? '0.00';

        return FranchisePayout::query()->updateOrCreate(
            ['franchise_id' => $f->id, 'period_start' => $start->toDateString()],
            [
                'period_end' => $end->toDateString(), 'status' => 'draft', 'stations_count' => count($stationIds),
                'sessions_count' => (int) ($byStation['totals']['sessions'] ?? 0), 'energy_kwh' => $byStation['totals']['kwh'] ?? '0',
                'net_sales' => $netSales, 'commission_percent' => $percent,
                'commission_amount' => bcdiv(bcmul((string) $netSales, $percent, 4), '100', 2), 'lines' => $lines,
            ],
        );
    }

    /** Scheduled on the 1st: drafts for last month for every active franchise. */
    public function generateMonthly(?CarbonImmutable $month = null): int
    {
        $month ??= CarbonImmutable::now(ReportFilters::TZ)->subMonthNoOverflow();

        return Franchise::query()->where('status', 'active')->get()->filter(fn (Franchise $f) => $this->generatePayout($f, $month) !== null)->count();
    }

    public function approvePayout(FranchisePayout $p, User $by): void
    {
        abort_unless($p->status === 'draft', 422);
        $p->forceFill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();
    }

    public function markPaid(FranchisePayout $p, User $by, string $reference): void
    {
        abort_unless($p->status === 'approved', 422);
        $p->forceFill(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => $reference])->save();
        $f = $p->franchise;
        \Illuminate\Support\Facades\Notification::route('mail', [$f->email => $f->contact_name])->notify(new FranchiseUpdate($f, 'franchise.payout_paid', [
            'month' => $p->period_start->format('F Y'), 'amount' => number_format((float) $p->commission_amount, 2), 'reference' => $reference,
        ]));
    }

    private function platformTeam()
    {
        $platform = \App\Models\Company::query()->where('is_platform', true)->value('id');

        return User::query()->whereNull('blocked_at')->where('company_id', $platform)->permission('franchises.manage')->limit(10)->get();
    }
}

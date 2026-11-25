<?php

namespace App\Services\Reports;

use App\Models\Charger;
use App\Models\Company;
use App\Models\PlatformFeeStatement;
use App\Models\Station;
use App\Models\Wallet;
use App\Support\BusinessRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase G reports. Figures come straight from the operational tables:
 *  - bookings (by slot start): online bookings, walk-ins, completed, cancelled, no-shows
 *  - charging_sessions (by start): sessions and energy delivered
 *  - payments / refunds (by paid / refunded time): money collected online and at the station, refunds
 *  - invoices, credit and debit notes (by issue date): net taxable sales and GST
 *  - no-show records and cancelled bookings: fees kept
 *  - station_energy_costs: electricity cost (kWh × rate + fixed charges spread over the month's days)
 *  - wallet ledger: platform fees charged (net of returns), shown excluding GST
 * Gross profit (estimate) = net sales − electricity cost − platform fees. Times are grouped in IST.
 */
class ReportService
{
    public const METRICS = [
        'bookings' => 'Online bookings', 'walk_ins' => 'Walk-ins', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_shows' => 'No-shows',
        'sessions' => 'Sessions', 'kwh' => 'Energy (kWh)',
        'collected_online' => 'Collected online', 'collected_station' => 'Collected at station', 'refunds' => 'Refunds', 'net_collected' => 'Net collected',
        'sales' => 'Net sales (excl. GST)', 'gst' => 'GST',
        'cancellation_kept' => 'Cancellation fees kept', 'no_show_kept' => 'No-show fees kept',
        'energy_cost' => 'Electricity cost', 'platform_fees' => 'Platform fees (excl. GST)', 'gross_profit' => 'Gross profit (est.)',
    ];

    /** Metrics that are counts or kWh (everything else is rupees). */
    public const COUNTS = ['bookings', 'walk_ins', 'completed', 'cancelled', 'no_shows', 'sessions'];

    private const IST = 'INTERVAL 330 MINUTE';

    // ── Main breakdowns ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  'period'|'station'|'charger'|'company'  $dimension
     * @return array{rows: list<array>, totals: array}
     */
    public function breakdown(ReportFilters $f, string $dimension = 'period'): array
    {
        $data = [];
        $add = function (iterable $rows) use (&$data) {
            foreach ($rows as $r) {
                $r = (array) $r;
                $k = (string) $r['k'];
                unset($r['k']);
                foreach ($r as $m => $v) {
                    $data[$k][$m] = bcadd((string) ($data[$k][$m] ?? '0'), (string) ($v ?? '0'), 3);
                }
            }
        };

        // Bookings by slot start (holds that were never paid are left out).
        $add($this->q('bookings', 'b', $f, 'b.start_at', $dimension)
            ->whereNotIn('b.status', ['held', 'expired'])
            ->selectRaw("SUM(b.source <> 'walk_in') bookings, SUM(b.source = 'walk_in') walk_ins, SUM(b.status = 'completed') completed, SUM(b.status = 'cancelled') cancelled, SUM(b.status = 'no_show') no_shows")
            ->get());

        // Sessions and energy.
        $add($this->q('charging_sessions', 's', $f, 's.started_at', $dimension)
            ->selectRaw('COUNT(*) sessions, COALESCE(SUM(s.energy_kwh), 0) kwh')->get());

        // Money collected for charging (booking and balance payments; operator wallet recharges excluded).
        $add($this->q('payments', 'p', $f, 'p.paid_at', $dimension, joinBooking: true)
            ->where('p.status', 'success')->whereIn('p.purpose', ['booking', 'balance'])
            ->selectRaw("COALESCE(SUM(CASE WHEN p.environment = 'offline' THEN 0 ELSE COALESCE(p.paid_amount, p.amount) END), 0) collected_online,
                         COALESCE(SUM(CASE WHEN p.environment = 'offline' THEN COALESCE(p.paid_amount, p.amount) ELSE 0 END), 0) collected_station")->get());

        $add($this->q('refunds', 'r', $f, 'r.refunded_at', $dimension, joinBooking: true)
            ->where('r.status', 'success')->selectRaw('COALESCE(SUM(r.amount), 0) refunds')->get());

        // Sales from GST documents: invoices + debit notes − credit notes.
        $add($this->q('invoices', 'i', $f, 'i.issued_at', $dimension, joinBooking: true)
            ->selectRaw('COALESCE(SUM(i.taxable_amount), 0) sales, COALESCE(SUM(i.total_tax), 0) gst')->get());
        $add($this->q('credit_notes', 'c', $f, 'c.issued_at', $dimension, joinBooking: true)
            ->selectRaw("COALESCE(SUM(CASE WHEN c.note_type = 'debit' THEN c.taxable_amount ELSE -c.taxable_amount END), 0) sales,
                         COALESCE(SUM(CASE WHEN c.note_type = 'debit' THEN c.total_tax ELSE -c.total_tax END), 0) gst")->get());

        $add($this->q('no_show_records', 'n', $f, 'n.marked_at', $dimension, joinBooking: true)
            ->selectRaw('COALESCE(SUM(n.retained_amount), 0) no_show_kept')->get());

        // Cancellation fees: what was paid and not refunded (refunds still in progress count as refunded).
        $add($this->q('bookings', 'b', $f, 'b.cancelled_at', $dimension)->where('b.status', 'cancelled')
            ->selectRaw("COALESCE(SUM(GREATEST(0,
                COALESCE((SELECT SUM(COALESCE(px.paid_amount, px.amount)) FROM payments px WHERE px.booking_id = b.id AND px.status = 'success' AND px.purpose = 'booking'), 0)
              - COALESCE((SELECT SUM(rx.amount) FROM refunds rx WHERE rx.booking_id = b.id AND rx.status <> 'failed'), 0))), 0) cancellation_kept")->get());

        // Platform fees from the operator wallet ledger (fees are debits; returns are credits).
        $gstFactor = bcadd('1', bcdiv(BusinessRules::get('platform_fees.gst_rate') ?? '18', '100', 4), 4);
        $add($this->q('wallet_transactions', 'w', $f, 'w.created_at', $dimension, joinBooking: true)
            ->whereIn('w.type', ['platform_fee', 'fee_reversal'])
            ->selectRaw('COALESCE(-SUM(w.amount), 0) / ? platform_fees', [$gstFactor])->get());

        // Electricity: energy × the station's rate for that month, plus fixed charges spread by day.
        $add($this->q('charging_sessions', 's', $f, 's.started_at', $dimension)
            ->join('station_energy_costs as e', function ($j) {
                $j->on('e.station_id', '=', 's.station_id')
                    ->whereRaw("e.month = DATE_FORMAT(DATE_ADD(s.started_at, ".self::IST."), '%Y-%m-01')");
            })
            ->selectRaw('COALESCE(SUM(COALESCE(s.energy_kwh, 0) * e.cost_per_kwh), 0) energy_cost')->get());
        if ($dimension !== 'charger') {
            $add($this->fixedCharges($f, $dimension));
        }

        $keys = $dimension === 'period' ? $this->periods($f) : array_keys($data);
        $labels = $this->labels($dimension, $keys, $f);
        $rows = [];
        foreach ($keys as $k) {
            $rows[] = ['key' => (string) $k, 'label' => $labels[$k] ?? (string) $k] + $this->finish($data[$k] ?? []);
        }
        if ($dimension !== 'period') {
            usort($rows, fn ($a, $b) => [-(float) $a['net_collected'], $a['label']] <=> [-(float) $b['net_collected'], $b['label']]);
        }
        $totals = $this->finish(array_reduce($rows, function ($carry, $r) {
            foreach (array_keys(self::METRICS) as $m) {
                $carry[$m] = bcadd((string) ($carry[$m] ?? '0'), (string) $r[$m], 3);
            }

            return $carry;
        }, []), recompute: false);

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Headline numbers for the selected range. */
    public function summary(ReportFilters $f): array
    {
        $t = $this->breakdown($f, 'period')['totals'];
        $paid = (int) $t['bookings'] + (int) $t['walk_ins'];
        $t['avg_ticket'] = $paid > 0 ? number_format((float) $t['net_collected'] / max(1, $paid - (int) $t['cancelled']), 2, '.', '') : '0.00';
        $t['online_share'] = bccomp(bcadd($t['collected_online'], $t['collected_station'], 2), '0', 2) > 0
            ? number_format(100 * (float) $t['collected_online'] / ((float) $t['collected_online'] + (float) $t['collected_station']), 1) : null;

        return $t;
    }

    /** Things waiting on money: unpaid walk-ins, balances to collect, refunds in progress, final-bill refunds to retry. */
    public function pending(ReportFilters $f): array
    {
        $base = fn () => $this->scoped(DB::table('bookings as b'), 'b', $f)->leftJoin('stations as st', 'st.id', '=', 'b.station_id');
        $sections = [
            'walk_ins' => ['Unpaid walk-ins', $base()->where('b.source', 'walk_in')->where('b.settlement', 'due')->where('b.status', 'completed'), 'b.total_amount', 'b.end_at'],
            'balances' => ['Final-bill balances to collect', $base()->where('b.source', '<>', 'walk_in')->where('b.trueup_status', 'due')->where('b.balance_due', '>', 0), 'b.balance_due', 'b.end_at'],
            'trueup_refunds' => ['Final-bill refunds to retry', $base()->where('b.trueup_status', 'refund_failed'), 'ABS(b.trueup_amount)', 'b.end_at'],
        ];
        $out = [];
        foreach ($sections as $key => [$label, $q, $amount, $since]) {
            $agg = (clone $q)->selectRaw("COUNT(*) n, COALESCE(SUM({$amount}), 0) amount")->first();
            $items = (clone $q)->orderBy($since)->limit(50)->get(['b.code', 'st.name as station', DB::raw("{$amount} as amount"), DB::raw("{$since} as since")]);
            $out[$key] = ['label' => $label, 'count' => (int) $agg->n, 'amount' => number_format((float) $agg->amount, 2, '.', ''), 'items' => $this->items($items)];
        }
        $refunds = $this->scoped(DB::table('refunds as r')->join('bookings as b', 'b.id', '=', 'r.booking_id'), 'b', $f)
            ->leftJoin('stations as st', 'st.id', '=', 'b.station_id')->whereNotIn('r.status', ['success', 'failed']);
        $agg = (clone $refunds)->selectRaw('COUNT(*) n, COALESCE(SUM(r.amount), 0) amount')->first();
        $out['refunds'] = ['label' => 'Refunds in progress', 'count' => (int) $agg->n, 'amount' => number_format((float) $agg->amount, 2, '.', ''),
            'items' => $this->items((clone $refunds)->orderBy('r.created_at')->limit(50)->get(['b.code', 'st.name as station', 'r.amount', 'r.created_at as since']))];

        return $out;
    }

    /** GST summary by supplier GSTIN, rate and B2B/B2C: invoices, credit notes and debit notes in the range. */
    public function gst(ReportFilters $f): array
    {
        $inv = $this->scoped(DB::table('invoices as i')->join('bookings as b', 'b.id', '=', 'i.booking_id'), 'b', $f)
            ->whereBetween('i.issued_at', [$f->fromUtc(), $f->toUtc()])
            ->groupBy('gstin', 'rate', 'type')
            ->selectRaw("i.supplier_gstin gstin, i.gst_rate rate, IF(i.recipient_gstin IS NULL OR i.recipient_gstin = '', 'B2C', 'B2B') type, COUNT(*) docs,
                SUM(i.taxable_amount) taxable, SUM(i.cgst) cgst, SUM(i.sgst) sgst, SUM(i.igst) igst")->get();
        $notes = $this->scoped(DB::table('credit_notes as c')->join('invoices as i', 'i.id', '=', 'c.invoice_id')->join('bookings as b', 'b.id', '=', 'c.booking_id'), 'b', $f)
            ->whereBetween('c.issued_at', [$f->fromUtc(), $f->toUtc()])
            ->groupBy('gstin', 'rate', 'type', 'c.note_type')
            ->selectRaw("c.supplier_gstin gstin, c.gst_rate rate, IF(i.recipient_gstin IS NULL OR i.recipient_gstin = '', 'B2C', 'B2B') type, c.note_type, COUNT(*) docs,
                SUM(c.taxable_amount) taxable, SUM(c.cgst) cgst, SUM(c.sgst) sgst, SUM(c.igst) igst")->get();

        $rows = [];
        $blank = ['invoices' => 0, 'taxable' => '0', 'cgst' => '0', 'sgst' => '0', 'igst' => '0', 'credit_notes' => 0, 'credit_taxable' => '0', 'credit_tax' => '0', 'debit_notes' => 0, 'debit_taxable' => '0', 'debit_tax' => '0'];
        foreach ($inv as $r) {
            $k = "{$r->gstin}|{$r->rate}|{$r->type}";
            $rows[$k] = ['gstin' => $r->gstin, 'rate' => (string) $r->rate, 'type' => $r->type] + $blank;
            $rows[$k] = array_merge($rows[$k], ['invoices' => (int) $r->docs, 'taxable' => (string) $r->taxable, 'cgst' => (string) $r->cgst, 'sgst' => (string) $r->sgst, 'igst' => (string) $r->igst]);
        }
        foreach ($notes as $r) {
            $k = "{$r->gstin}|{$r->rate}|{$r->type}";
            $rows[$k] ??= ['gstin' => $r->gstin, 'rate' => (string) $r->rate, 'type' => $r->type] + $blank;
            $p = $r->note_type === 'debit' ? 'debit' : 'credit';
            $rows[$k]["{$p}_notes"] += (int) $r->docs;
            $rows[$k]["{$p}_taxable"] = bcadd($rows[$k]["{$p}_taxable"], (string) $r->taxable, 2);
            $rows[$k]["{$p}_tax"] = bcadd($rows[$k]["{$p}_tax"], bcadd(bcadd((string) $r->cgst, (string) $r->sgst, 2), (string) $r->igst, 2), 2);
        }
        $rows = array_values(array_map(function ($r) {
            $tax = bcadd(bcadd($r['cgst'], $r['sgst'], 2), $r['igst'], 2);
            $r['net_taxable'] = bcadd(bcsub($r['taxable'], $r['credit_taxable'], 2), $r['debit_taxable'], 2);
            $r['net_tax'] = bcadd(bcsub($tax, $r['credit_tax'], 2), $r['debit_tax'], 2);

            return $r;
        }, $rows));
        usort($rows, fn ($a, $b) => [$a['gstin'], $a['rate'], $a['type']] <=> [$b['gstin'], $b['rate'], $b['type']]);
        $totals = [];
        foreach (['invoices', 'taxable', 'cgst', 'sgst', 'igst', 'credit_notes', 'credit_taxable', 'credit_tax', 'debit_notes', 'debit_taxable', 'debit_tax', 'net_taxable', 'net_tax'] as $m) {
            $totals[$m] = in_array($m, ['invoices', 'credit_notes', 'debit_notes'], true)
                ? array_sum(array_column($rows, $m))
                : array_reduce($rows, fn ($c, $r) => bcadd($c, $r[$m], 2), '0');
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Platform-fee statements (by period start) and operator wallets. Station filters do not apply (fees are per operator). */
    public function platformFees(ReportFilters $f): array
    {
        $statements = PlatformFeeStatement::query()->with('company:id,trade_name')
            ->when($f->companyId, fn ($q) => $q->where('company_id', $f->companyId))
            ->whereBetween('period_start', [$f->from->toDateString(), $f->to->toDateString()])
            ->where('status', '<>', 'draft')->orderBy('period_start')->orderBy('company_id')->get();
        $rows = $statements->map(fn (PlatformFeeStatement $s) => [
            'company' => $s->company?->trade_name, 'number' => $s->number, 'period' => $s->period_start?->format('M Y'), 'status' => $s->status,
            'taxable' => (string) $s->taxable_amount, 'gst' => (string) $s->total_tax, 'total' => (string) $s->total,
            'reimbursement' => (string) ($s->reimbursement_amount ?? '0'), 'net_payable' => (string) $s->net_payable,
            'paid' => (string) ($s->paid_amount ?? '0'), 'outstanding' => number_format(max(0, (float) $s->net_payable - (float) ($s->paid_amount ?? 0)), 2, '.', ''),
        ])->all();

        $fees = DB::table('wallet_transactions')->whereBetween('created_at', [$f->fromUtc(), $f->toUtc()])
            ->when($f->companyId, fn ($q) => $q->where('company_id', $f->companyId))
            ->groupBy('company_id')
            ->selectRaw("company_id, -SUM(CASE WHEN type IN ('platform_fee','fee_reversal') THEN amount ELSE 0 END) fees,
                         SUM(CASE WHEN type IN ('recharge','manual_credit') THEN amount ELSE 0 END) added,
                         SUM(CASE WHEN type = 'coupon_credit' THEN amount ELSE 0 END) coupon_credits")->get()->keyBy('company_id');
        $wallets = Wallet::query()->with('company:id,trade_name,billing_mode')
            ->when($f->companyId, fn ($q) => $q->where('company_id', $f->companyId))->get()
            ->map(fn (Wallet $w) => [
                'company' => $w->company?->trade_name, 'balance' => (string) $w->balance,
                'fees' => number_format((float) ($fees[$w->company_id]->fees ?? 0), 2, '.', ''),
                'added' => number_format((float) ($fees[$w->company_id]->added ?? 0), 2, '.', ''),
                'coupon_credits' => number_format((float) ($fees[$w->company_id]->coupon_credits ?? 0), 2, '.', ''),
                'low' => $w->low_balance_alert !== null && bccomp((string) $w->balance, (string) $w->low_balance_alert, 2) < 0,
            ])->sortBy('company')->values()->all();

        $sum = fn (array $rows, string $k) => array_reduce($rows, fn ($c, $r) => bcadd($c, (string) $r[$k], 2), '0');

        return [
            'statements' => $rows,
            'statement_totals' => ['total' => $sum($rows, 'total'), 'net_payable' => $sum($rows, 'net_payable'), 'paid' => $sum($rows, 'paid'), 'outstanding' => $sum($rows, 'outstanding')],
            'wallets' => $wallets,
            'wallet_totals' => ['balance' => $sum($wallets, 'balance'), 'fees' => $sum($wallets, 'fees'), 'added' => $sum($wallets, 'added')],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────────────────

    /** Grouped base query on a table, with scope and time range applied; joins bookings when the table has no station/charger. */
    private function q(string $table, string $alias, ReportFilters $f, string $timeCol, string $dimension, bool $joinBooking = false): Builder
    {
        $q = DB::table("{$table} as {$alias}");
        $on = $alias;
        if ($joinBooking) {
            $q->join('bookings as b', 'b.id', '=', "{$alias}.booking_id");
            $on = 'b';
        }
        $this->scoped($q, $on, $f)->whereBetween($timeCol, [$f->fromUtc(), $f->toUtc()]);
        $key = match ($dimension) {
            'station' => "{$on}.station_id",
            'charger' => "{$on}.charger_id",
            'company' => "{$on}.company_id",
            default => $this->periodExpr($timeCol, $f->group),
        };

        return $q->selectRaw("{$key} k")->groupBy(DB::raw($key))->whereNotNull(DB::raw($key));
    }

    private function scoped(Builder $q, string $alias, ReportFilters $f): Builder
    {
        return $q->when($f->companyId, fn ($q) => $q->where("{$alias}.company_id", $f->companyId))
            ->when($f->stationIds !== null, fn ($q) => $q->whereIn("{$alias}.station_id", $f->stationIds));
    }

    private function periodExpr(string $col, string $group): string
    {
        $ist = "DATE_ADD({$col}, ".self::IST.')';

        return match ($group) {
            'week' => "DATE_FORMAT(DATE_SUB(DATE({$ist}), INTERVAL WEEKDAY({$ist}) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT({$ist}, '%Y-%m')",
            'year' => "DATE_FORMAT({$ist}, '%Y')",
            default => "DATE_FORMAT({$ist}, '%Y-%m-%d')",
        };
    }

    private function periodKey(CarbonImmutable $d, string $group): string
    {
        return match ($group) {
            'week' => $d->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'month' => $d->format('Y-m'),
            'year' => $d->format('Y'),
            default => $d->format('Y-m-d'),
        };
    }

    /** @return list<string> every bucket in the range, so quiet days still show */
    private function periods(ReportFilters $f): array
    {
        $keys = [];
        for ($d = $f->from; $d->lte($f->to); $d = $d->addDay()) {
            $keys[$this->periodKey($d, $f->group)] = true;
        }

        return array_keys($keys);
    }

    /** Monthly fixed / demand charges spread evenly over the month's days, then grouped like everything else. */
    private function fixedCharges(ReportFilters $f, string $dimension): array
    {
        $costs = DB::table('station_energy_costs')->where('fixed_charges', '>', 0)
            ->whereBetween('month', [$f->from->startOfMonth()->toDateString(), $f->to->toDateString()])
            ->when($f->companyId, fn ($q) => $q->where('company_id', $f->companyId))
            ->when($f->stationIds !== null, fn ($q) => $q->whereIn('station_id', $f->stationIds))
            ->get(['station_id', 'company_id', 'month', 'fixed_charges']);
        $out = [];
        foreach ($costs as $c) {
            $month = CarbonImmutable::parse($c->month, ReportFilters::TZ)->startOfMonth();
            $perDay = (float) $c->fixed_charges / $month->daysInMonth;
            $start = $month->max($f->from);
            $end = $month->endOfMonth()->startOfDay()->min($f->to);
            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                $k = match ($dimension) { 'station' => $c->station_id, 'company' => $c->company_id, default => $this->periodKey($d, $f->group) };
                $out[] = ['k' => $k, 'energy_cost' => number_format($perDay, 4, '.', '')];
            }
        }

        return $out;
    }

    /** Round, fill missing metrics and work out the derived ones. */
    private function finish(array $m, bool $recompute = true): array
    {
        $out = [];
        foreach (array_keys(self::METRICS) as $k) {
            $v = (string) ($m[$k] ?? '0');
            $out[$k] = in_array($k, self::COUNTS, true) ? (int) round((float) $v) : ($k === 'kwh' ? number_format((float) $v, 3, '.', '') : number_format((float) $v, 2, '.', ''));
        }
        if ($recompute) {
            $out['net_collected'] = number_format((float) $out['collected_online'] + (float) $out['collected_station'] - (float) $out['refunds'], 2, '.', '');
            $out['gross_profit'] = number_format((float) $out['sales'] - (float) $out['energy_cost'] - (float) $out['platform_fees'], 2, '.', '');
        }

        return $out;
    }

    private function labels(string $dimension, array $keys, ReportFilters $f): array
    {
        return match ($dimension) {
            'station' => Station::query()->whereIn('id', $keys)->get(['id', 'name', 'city'])->mapWithKeys(fn ($s) => [$s->id => "{$s->name}, {$s->city}"])->all(),
            'charger' => Charger::query()->with('station:id,name')->whereIn('id', $keys)->get()->mapWithKeys(fn ($c) => [$c->id => trim(($c->code ?? $c->name ?? '#'.$c->id).' · '.$c->station?->name)])->all(),
            'company' => Company::query()->whereIn('id', $keys)->pluck('trade_name', 'id')->all(),
            default => collect($keys)->mapWithKeys(fn ($k) => [$k => match ($f->group) {
                'week' => 'Week of '.CarbonImmutable::parse($k)->format('j M Y'),
                'month' => CarbonImmutable::parse($k.'-01')->format('M Y'),
                'year' => (string) $k,
                default => CarbonImmutable::parse($k)->format('D, j M Y'),
            }])->all(),
        };
    }

    private function items(iterable $rows): array
    {
        return collect($rows)->map(fn ($r) => [
            'code' => $r->code, 'station' => $r->station, 'amount' => number_format((float) $r->amount, 2, '.', ''),
            'since' => $r->since ? CarbonImmutable::parse($r->since, 'UTC')->setTimezone(ReportFilters::TZ)->format('j M Y, g:i A') : null,
        ])->all();
    }
}

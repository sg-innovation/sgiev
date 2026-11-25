@php
    $inr = fn ($v, $dec = 0) => 'Rs '.\App\Support\IndianNumber::format($v, $dec);
    $num = fn ($v, $dec = 0) => \App\Support\IndianNumber::format($v, $dec);
    $cell = fn (string $m, $v) => in_array($m, \App\Services\Reports\ReportService::COUNTS, true) ? $num($v) : ($m === 'kwh' ? $num($v, 1) : $inr($v));
    // Compact column set for the PDF (A4 landscape); the Excel file has every column.
    $pdfCols = ['bookings' => 'Online', 'walk_ins' => 'Walk-in', 'cancelled' => 'Cancel', 'no_shows' => 'No-show', 'kwh' => 'kWh',
        'collected_online' => 'Online', 'collected_station' => 'Station', 'refunds' => 'Refunds', 'net_collected' => 'Net collected',
        'sales' => 'Net sales', 'gst' => 'GST', 'energy_cost' => 'Electricity', 'platform_fees' => 'Fees', 'gross_profit' => 'Profit (est.)'];
    $s = $d['summary'];
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Trans EV business report</title>
<style>
    @page { margin: 14mm 10mm 14mm 10mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #1f2937; }
    h1 { font-size: 16px; margin: 0 0 2px; color: #111827; }
    h2 { font-size: 11px; margin: 14px 0 5px; color: #111827; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3f4f6; color: #374151; font-weight: bold; text-align: right; padding: 3px 4px; border-bottom: 1px solid #d1d5db; }
    td { text-align: right; padding: 2.5px 4px; border-bottom: 1px solid #f0f1f3; }
    th.l, td.l { text-align: left; }
    tfoot td { font-weight: bold; background: #f9fafb; border-top: 1px solid #d1d5db; }
    .kpis td { border: 1px solid #e5e7eb; text-align: left; padding: 6px 8px; width: 16.6%; vertical-align: top; }
    .kpis .v { font-size: 13px; font-weight: bold; color: #111827; }
    .neg { color: #b91c1c; }
    .page-break { page-break-before: always; }
    .footer { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5px; color: #9ca3af; }
</style>
</head>
<body>
<div class="footer">Trans EV business report · {{ $f->label() }} · generated {{ $generatedAt->format('j M Y, g:i A') }} IST · Profit is an estimate (net sales − electricity − platform fees).</div>

<h1>Business report</h1>
<div class="muted">{{ $f->label() }} · {{ $f->scopeLabel() }} · grouped by {{ strtolower(\App\Services\Reports\ReportFilters::GROUPS[$f->group]) }}</div>

<table class="kpis" style="margin-top:10px">
    <tr>
        <td>Net collected<div class="v">{{ $inr($s['net_collected']) }}</div><span class="muted">Online {{ $inr($s['collected_online']) }} · station {{ $inr($s['collected_station']) }} · refunds {{ $inr($s['refunds']) }}</span></td>
        <td>Net sales (excl. GST)<div class="v">{{ $inr($s['sales']) }}</div><span class="muted">GST {{ $inr($s['gst']) }}</span></td>
        <td>Gross profit (est.)<div class="v">{{ $inr($s['gross_profit']) }}</div><span class="muted">Electricity {{ $inr($s['energy_cost']) }} · fees {{ $inr($s['platform_fees']) }}</span></td>
        <td>Bookings<div class="v">{{ $num($s['bookings'] + $s['walk_ins']) }}</div><span class="muted">{{ $num($s['walk_ins']) }} walk-ins · {{ $num($s['completed']) }} completed</span></td>
        <td>Energy<div class="v">{{ $num($s['kwh'], 1) }} kWh</div><span class="muted">{{ $num($s['sessions']) }} sessions</span></td>
        <td>Cancelled · no-show<div class="v">{{ $num($s['cancelled']) }} · {{ $num($s['no_shows']) }}</div><span class="muted">Fees kept {{ $inr((float) $s['cancellation_kept'] + (float) $s['no_show_kept']) }}</span></td>
    </tr>
</table>

@foreach (['period' => 'By '.strtolower(\App\Services\Reports\ReportFilters::GROUPS[$f->group]), 'stations' => 'By station', 'chargers' => 'By charger', 'operators' => 'By operator'] as $key => $title)
    @if (isset($d[$key]) && $d[$key]['rows'])
        <h2>{{ $title }}</h2>
        <table>
            <thead>
                <tr><th class="l"></th><th colspan="5" style="text-align:center">Bookings &amp; energy</th><th colspan="4" style="text-align:center">Money collected (Rs)</th><th colspan="5" style="text-align:center">Sales, costs &amp; profit (Rs)</th></tr>
                <tr><th class="l">{{ ['period' => 'Period', 'stations' => 'Station', 'chargers' => 'Charger', 'operators' => 'Operator'][$key] }}</th>
                    @foreach ($pdfCols as $label)<th>{{ $label }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach ($d[$key]['rows'] as $r)
                    @continue($key === 'period' && (int) $r['bookings'] + (int) $r['walk_ins'] === 0 && (float) $r['net_collected'] == 0 && (float) $r['sales'] == 0)
                    <tr><td class="l">{{ \Illuminate\Support\Str::limit($r['label'], 48) }}</td>
                        @foreach ($pdfCols as $m => $label)<td class="{{ $m === 'gross_profit' && (float) $r[$m] < 0 ? 'neg' : '' }}">{{ $cell($m, $r[$m]) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
            <tfoot><tr><td class="l">Total</td>@foreach ($pdfCols as $m => $label)<td>{{ $cell($m, $d[$key]['totals'][$m] ?? 0) }}</td>@endforeach</tr></tfoot>
        </table>
        @if ($key === 'period')<div class="muted" style="margin-top:3px">Periods with no activity are left out of the PDF.</div>@endif
    @endif
@endforeach

@if (isset($d['gst']) && $d['gst']['rows'])
    <h2 class="page-break">GST summary (by issue date)</h2>
    <table>
        <thead><tr><th class="l">Supplier GSTIN</th><th>Rate</th><th>Type</th><th>Invoices</th><th>Taxable</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Credit notes</th><th>Credit taxable</th><th>Debit notes</th><th>Debit taxable</th><th>Net taxable</th><th>Net tax</th></tr></thead>
        <tbody>
            @foreach ($d['gst']['rows'] as $r)
                <tr><td class="l">{{ $r['gstin'] }}</td><td>{{ rtrim(rtrim($r['rate'], '0'), '.') }}%</td><td>{{ $r['type'] }}</td><td>{{ $r['invoices'] }}</td>
                    <td>{{ $inr($r['taxable'], 2) }}</td><td>{{ $inr($r['cgst'], 2) }}</td><td>{{ $inr($r['sgst'], 2) }}</td><td>{{ $inr($r['igst'], 2) }}</td>
                    <td>{{ $r['credit_notes'] }}</td><td>{{ $inr($r['credit_taxable'], 2) }}</td><td>{{ $r['debit_notes'] }}</td><td>{{ $inr($r['debit_taxable'], 2) }}</td>
                    <td>{{ $inr($r['net_taxable'], 2) }}</td><td>{{ $inr($r['net_tax'], 2) }}</td></tr>
            @endforeach
        </tbody>
        @php $t = $d['gst']['totals']; @endphp
        <tfoot><tr><td class="l" colspan="3">Total</td><td>{{ $t['invoices'] }}</td><td>{{ $inr($t['taxable'], 2) }}</td><td>{{ $inr($t['cgst'], 2) }}</td><td>{{ $inr($t['sgst'], 2) }}</td><td>{{ $inr($t['igst'], 2) }}</td>
            <td>{{ $t['credit_notes'] }}</td><td>{{ $inr($t['credit_taxable'], 2) }}</td><td>{{ $t['debit_notes'] }}</td><td>{{ $inr($t['debit_taxable'], 2) }}</td><td>{{ $inr($t['net_taxable'], 2) }}</td><td>{{ $inr($t['net_tax'], 2) }}</td></tr></tfoot>
    </table>
@endif

@if (isset($d['pending']))
    <h2>Pending money (snapshot)</h2>
    <table>
        <thead><tr><th class="l">Type</th><th>Count</th><th>Amount</th></tr></thead>
        <tbody>@foreach ($d['pending'] as $p)<tr><td class="l">{{ $p['label'] }}</td><td>{{ $p['count'] }}</td><td>{{ $inr($p['amount'], 2) }}</td></tr>@endforeach</tbody>
    </table>
@endif

@if (isset($d['fees']) && ($d['fees']['statements'] || $d['fees']['wallets']))
    <h2>Platform fees</h2>
    @if ($d['fees']['statements'])
        <table>
            <thead><tr><th class="l">Operator</th><th class="l">Number</th><th class="l">Month</th><th class="l">Status</th><th>Total</th><th>Coupon credit</th><th>Net payable</th><th>Paid</th><th>Outstanding</th></tr></thead>
            <tbody>@foreach ($d['fees']['statements'] as $r)
                <tr><td class="l">{{ $r['company'] }}</td><td class="l">{{ $r['number'] }}</td><td class="l">{{ $r['period'] }}</td><td class="l">{{ ucfirst($r['status']) }}</td>
                    <td>{{ $inr($r['total'], 2) }}</td><td>{{ $inr($r['reimbursement'], 2) }}</td><td>{{ $inr($r['net_payable'], 2) }}</td><td>{{ $inr($r['paid'], 2) }}</td><td>{{ $inr($r['outstanding'], 2) }}</td></tr>
            @endforeach</tbody>
        </table>
    @endif
    @if ($d['fees']['wallets'])
        <table style="margin-top:8px">
            <thead><tr><th class="l">Wallet</th><th>Fees charged</th><th>Funds added</th><th>Coupon credits</th><th>Balance now</th></tr></thead>
            <tbody>@foreach ($d['fees']['wallets'] as $w)<tr><td class="l">{{ $w['company'] }}</td><td>{{ $inr($w['fees'], 2) }}</td><td>{{ $inr($w['added'], 2) }}</td><td>{{ $inr($w['coupon_credits'], 2) }}</td><td>{{ $inr($w['balance'], 2) }}</td></tr>@endforeach</tbody>
        </table>
    @endif
@endif
</body>
</html>

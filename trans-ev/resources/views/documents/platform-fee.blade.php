@php
    $s = $doc; $sup = $s->supplier ?? []; $rec = $s->recipient ?? [];
    $tz = \App\Models\Station::TIMEZONE;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $half = rtrim(rtrim(number_format((float) $s->gst_rate / 2, 2), '0'), '.');
    $draft = $s->status === 'draft';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Platform fees {{ $s->number ?? $s->periodLabel() }}</title>
    @include('documents._styles')
</head>
<body>
    @if ($draft)
        <div class="watermark">DRAFT STATEMENT: not a tax invoice until issued</div>
    @endif

    <table>
        <tr>
            <td style="width: 58%; vertical-align: top;">
                <div class="brand">TRANS <span>EV</span></div>
                <div class="bold" style="margin-top: 6px; font-size: 11px;">{{ $sup['legal_name'] ?? '' }}</div>
                <div class="muted">{{ $sup['address'] ?? '' }}</div>
                <div><span class="bold">GSTIN:</span> <span class="mono">{{ $s->supplier_gstin }}</span>
                    @if ($sup['pan'] ?? null) &nbsp;·&nbsp; <span class="bold">PAN:</span> <span class="mono">{{ $sup['pan'] }}</span> @endif</div>
                <div><span class="bold">State:</span> {{ $sup['state'] ?? '' }} ({{ substr((string) $s->supplier_gstin, 0, 2) }})</div>
            </td>
            <td style="width: 42%; vertical-align: top;" class="right">
                <h1>{{ $draft ? 'STATEMENT (DRAFT)' : 'TAX INVOICE' }}</h1>
                <div class="muted small">Platform services · {{ $s->periodLabel() }}</div>
                <table style="margin-top: 8px;">
                    @if ($s->number)
                        <tr><td class="muted right">Invoice no.</td><td class="right bold mono">{{ $s->number }}</td></tr>
                        <tr><td class="muted right">Invoice date</td><td class="right">{{ $s->issued_at?->setTimezone($tz)->format('d M Y') }}</td></tr>
                        <tr><td class="muted right">Due by</td><td class="right">{{ $s->due_on?->format('d M Y') }}</td></tr>
                    @endif
                    <tr><td class="muted right">Period</td><td class="right">{{ $s->period_start->format('d M') }} – {{ $s->period_end->format('d M Y') }}</td></tr>
                    <tr><td class="muted right">Place of supply</td><td class="right">{{ \App\Support\IndianStates::name($s->place_of_supply) }} ({{ $s->place_of_supply }})</td></tr>
                    <tr><td class="muted right">Reverse charge</td><td class="right">No</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 12px;">
        <tr>
            <td class="box" style="width: 50%;">
                <div class="label">Bill to</div>
                <div class="bold" style="font-size: 10.5px;">{{ $rec['legal_name'] ?? '' }}</div>
                @if ($rec['trade_name'] ?? null) <div class="muted">{{ $rec['trade_name'] }}</div> @endif
                @if ($s->recipient_gstin) <div><span class="bold">GSTIN:</span> <span class="mono">{{ $s->recipient_gstin }}</span></div> @endif
                <div class="muted">{{ $rec['address'] ?? '' }}</div>
            </td>
            <td style="width: 2%;"></td>
            <td class="box" style="width: 48%;">
                <div class="label">Activity in {{ $s->periodLabel() }}</div>
                <div>Paid bookings: <span class="bold">{{ $s->bookings_count }}</span></div>
                <div>Net charging sales (excl. GST): <span class="bold">{{ $money($s->sales_taxable) }}</span></div>
                <div>Commission rate: <span class="bold">{{ rtrim(rtrim((string) $s->commission_percent, '0'), '.') }}%</span></div>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 12px;">
        <thead><tr><th style="width: 4%;">#</th><th>Description</th><th style="width: 10%;">SAC</th><th style="width: 8%;" class="right">Qty</th><th style="width: 14%;" class="right">Rate</th><th style="width: 14%;" class="right">Amount</th></tr></thead>
        <tbody>
            @forelse ($s->lines as $i => $l)
                <tr><td>{{ $i + 1 }}</td><td>{{ $l['description'] }}</td><td class="mono">{{ $l['sac'] }}</td><td class="right">{{ $l['qty'] }}</td><td class="right">{{ $money($l['rate']) }}</td><td class="right">{{ $money($l['amount']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No platform fees for this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 12px;">
                <span class="label">Amount in words</span><br><span class="bold">{{ \App\Services\PlatformFeeService::amountInWords($s) }}</span>
                @if ((float) $s->reimbursement_amount > 0)
                    <div class="box" style="margin-top: 10px;">
                        <span class="label">Settlement (not part of this invoice)</span><br>
                        Less: discounts funded by Trans EV through platform coupons, reimbursed to you: <span class="bold">− {{ $money($s->reimbursement_amount) }}</span><br>
                        <span class="bold">Net amount {{ (float) $s->net_payable >= 0 ? 'payable to Trans EV' : 'payable to you' }}: {{ $money(abs((float) $s->net_payable)) }}</span>
                    </div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals">
                    <tr><td class="bold">Taxable value</td><td class="right bold">{{ $money($s->taxable_amount) }}</td></tr>
                    @if ((float) $s->igst > 0)
                        <tr><td class="muted">IGST @ {{ rtrim(rtrim((string) $s->gst_rate, '0'), '.') }}%</td><td class="right">{{ $money($s->igst) }}</td></tr>
                    @else
                        <tr><td class="muted">CGST @ {{ $half }}%</td><td class="right">{{ $money($s->cgst) }}</td></tr>
                        <tr><td class="muted">SGST/UTGST @ {{ $half }}%</td><td class="right">{{ $money($s->sgst) }}</td></tr>
                    @endif
                    <tr class="grand"><td>Invoice total</td><td class="right">{{ $money($s->total) }}</td></tr>
                </table>
                @unless ($draft)
                    <div class="stamp" style="margin-top: 14px;">
                        <div class="small muted">For {{ $sup['legal_name'] ?? '' }}</div>
                        <div style="margin-top: 16px;" class="bold">Authorised signatory</div>
                        <div class="small muted">Electronically generated · fingerprint <span class="mono">{{ substr((string) $s->content_hash, 0, 16) }}</span></div>
                    </div>
                @endunless
            </td>
        </tr>
    </table>

    <div class="footer small muted">Platform services provided by {{ $sup['legal_name'] ?? '' }} for listing, booking and payment collection on transevrecharge.com. Please pay by the due date and quote the invoice number. Questions: {{ $sup['email'] ?? '' }}.</div>
</body>
</html>

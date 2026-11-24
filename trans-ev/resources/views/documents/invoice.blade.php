@php
    $s = $doc->supplier; $r = $doc->recipient; $site = $doc->site;
    $tz = \App\Models\Station::TIMEZONE;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $posName = \App\Support\IndianStates::name($doc->place_of_supply);
    $half = rtrim(rtrim(number_format((float) $doc->gst_rate / 2, 2), '0'), '.');
    $isTest = optional($doc->payment)->environment === 'simulator';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tax invoice {{ $doc->number }}</title>
    @include('documents._styles')
</head>
<body>
    @if ($isTest)
        <div class="watermark">TEST DOCUMENT (simulated payment): not valid for tax purposes</div>
    @endif

    <table>
        <tr>
            <td style="width: 58%; vertical-align: top;">
                @if ($logo)
                    <img src="{{ $logo }}" style="height: 30px;" alt="">
                @else
                    <div class="brand">TRANS <span>EV</span></div>
                @endif
                <div class="bold" style="margin-top: 6px; font-size: 11px;">{{ $s['legal_name'] }}</div>
                @if (($s['trade_name'] ?? null) && $s['trade_name'] !== $s['legal_name'])
                    <div class="muted">Trade name: {{ $s['trade_name'] }}</div>
                @endif
                <div class="muted">{{ $s['address'] }}</div>
                <div><span class="bold">GSTIN:</span> <span class="mono">{{ $doc->supplier_gstin }}</span>
                    @if ($s['pan'] ?? null) &nbsp;·&nbsp; <span class="bold">PAN:</span> <span class="mono">{{ $s['pan'] }}</span> @endif</div>
                <div><span class="bold">State:</span> {{ $s['state'] }} ({{ $doc->supplier_state_code }})</div>
                @if ($s['cin'] ?? null) <div class="muted">CIN: {{ $s['cin'] }}</div> @endif
                <div class="muted">{{ $s['email'] }} @if ($s['phone'] ?? null) · {{ $s['phone'] }} @endif</div>
            </td>
            <td style="width: 42%; vertical-align: top;" class="right">
                <h1>TAX INVOICE</h1>
                <div class="muted small">Original for recipient</div>
                <table style="margin-top: 8px;">
                    <tr><td class="muted right">Invoice no.</td><td class="right bold mono">{{ $doc->number }}</td></tr>
                    <tr><td class="muted right">Invoice date</td><td class="right">{{ $doc->issued_at->setTimezone($tz)->format('d M Y') }}</td></tr>
                    <tr><td class="muted right">Place of supply</td><td class="right">{{ $posName }} ({{ $doc->place_of_supply }})</td></tr>
                    <tr><td class="muted right">Reverse charge</td><td class="right">{{ $doc->reverse_charge ? 'Yes' : 'No' }}</td></tr>
                    <tr><td class="muted right">Supply type</td><td class="right">{{ $doc->supply_type }} · Services</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 12px;">
        <tr>
            <td class="box" style="width: 50%;">
                <div class="label">Bill to</div>
                <div class="bold" style="font-size: 10.5px;">{{ $r['name'] }}</div>
                @if ($r['gstin']) <div><span class="bold">GSTIN:</span> <span class="mono">{{ $r['gstin'] }}</span></div> @endif
                <div class="muted">{{ $r['email'] }} @if ($r['phone']) · +91 {{ $r['phone'] }} @endif</div>
                @if ($r['state']) <div>State: {{ $r['state'] }} ({{ $r['state_code'] }})</div> @endif
            </td>
            <td style="width: 2%;"></td>
            <td class="box" style="width: 48%;">
                <div class="label">Service location</div>
                <div class="bold">{{ $site['station'] }} <span class="muted">({{ $site['station_code'] }})</span></div>
                <div class="muted">{{ $site['address'] }}</div>
                <div>Booking <span class="mono bold">{{ $site['booking_code'] }}</span> · {{ $site['slot'] }} IST</div>
                <div>Charge point <span class="mono">{{ $site['charge_point_id'] }}</span> · {{ $site['charger'] }} · Connector {{ $site['connector'] }}</div>
                @if ($site['electrical'] ?? null) <div class="muted">{{ $site['electrical'] }}</div> @endif
                @if ($site['meter'] ?? null) <div class="muted">Energy meter: {{ $site['meter'] }}</div> @endif
                @if ($site['vehicle'] ?? null) <div>Vehicle: <span class="mono">{{ $site['vehicle'] }}</span></div> @endif
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 12px;">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>Description</th>
                <th style="width: 10%;">SAC</th>
                <th style="width: 10%;" class="right">Qty</th>
                <th style="width: 7%;">Unit</th>
                <th style="width: 12%;" class="right">Rate</th>
                <th style="width: 14%;" class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc->lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="mono">{{ $line['sac'] }}</td>
                    <td class="right">{{ rtrim(rtrim($line['qty'], '0'), '.') }}</td>
                    <td>{{ $line['unit'] }}</td>
                    <td class="right">{{ $money($line['rate']) }}</td>
                    <td class="right">{{ $money($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 12px;">
                <table class="summary">
                    <tr><th>SAC</th><th>Taxable value</th>
                        @if ((float) $doc->igst > 0) <th>IGST rate</th><th>IGST</th> @else <th>CGST</th><th>SGST</th> @endif
                        <th>Total tax</th></tr>
                    <tr>
                        <td class="mono">{{ $doc->sac_code }}</td>
                        <td>{{ $money($doc->taxable_amount) }}</td>
                        @if ((float) $doc->igst > 0)
                            <td>{{ rtrim(rtrim((string) $doc->gst_rate, '0'), '.') }}%</td><td>{{ $money($doc->igst) }}</td>
                        @else
                            <td>{{ $half }}% · {{ $money($doc->cgst) }}</td><td>{{ $half }}% · {{ $money($doc->sgst) }}</td>
                        @endif
                        <td>{{ $money($doc->total_tax) }}</td>
                    </tr>
                </table>
                <div style="margin-top: 8px;"><span class="label">Amount in words</span><br><span class="bold">{{ $doc->amount_in_words }}</span></div>
                @if ($site['payment_ref'] ?? null)
                    <div style="margin-top: 8px;" class="small">
                        <span class="label">Payment received</span><br>
                        {{ $site['payment_mode'] ?? 'Online' }} · {{ $site['paid_at'] }} IST<br>
                        Ref. <span class="mono">{{ $site['payment_ref'] }}</span> @if ($site['rrn'] ?? null) · RRN <span class="mono">{{ $site['rrn'] }}</span> @endif
                    </div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals">
                    <tr><td class="muted">Subtotal</td><td class="right">{{ $money($doc->subtotal) }}</td></tr>
                    @if ((float) $doc->discount > 0)
                        <tr><td class="muted">Less: discount @if ($site['coupon'] ?? null) (coupon {{ $site['coupon'] }}) @endif</td><td class="right">− {{ $money($doc->discount) }}</td></tr>
                    @endif
                    <tr><td class="bold">Taxable value</td><td class="right bold">{{ $money($doc->taxable_amount) }}</td></tr>
                    @if ((float) $doc->igst > 0)
                        <tr><td class="muted">IGST @ {{ rtrim(rtrim((string) $doc->gst_rate, '0'), '.') }}%</td><td class="right">{{ $money($doc->igst) }}</td></tr>
                    @else
                        <tr><td class="muted">CGST @ {{ $half }}%</td><td class="right">{{ $money($doc->cgst) }}</td></tr>
                        <tr><td class="muted">SGST/UTGST @ {{ $half }}%</td><td class="right">{{ $money($doc->sgst) }}</td></tr>
                    @endif
                    @if ((float) $doc->round_off !== 0.0)
                        <tr><td class="muted">Round off</td><td class="right">{{ $money($doc->round_off) }}</td></tr>
                    @endif
                    <tr class="grand"><td>Invoice total</td><td class="right">{{ $money($doc->total) }}</td></tr>
                </table>
                <div class="stamp" style="margin-top: 14px;">
                    <div class="small muted">For {{ $s['legal_name'] }}</div>
                    <div style="margin-top: 16px;" class="bold">Authorised signatory</div>
                    <div class="small muted">Electronically generated · fingerprint <span class="mono">{{ substr($doc->content_hash, 0, 16) }}</span></div>
                </div>
            </td>
        </tr>
    </table>

    @if ($doc->creditNotes->isNotEmpty())
        <div style="margin-top: 10px;" class="box">
            <span class="label">Credit notes issued against this invoice</span><br>
            @foreach ($doc->creditNotes as $cn)
                <span class="mono">{{ $cn->number }}</span> · {{ $cn->issued_at->setTimezone($tz)->format('d M Y') }} · {{ $money($cn->total) }}@if (! $loop->last); @endif
            @endforeach
        </div>
    @endif

    <div class="footer small muted">
        <div>Energy is billed on the pre-paid estimate for the booked slot; any difference after charging is settled by a credit note or a supplementary invoice as per the terms.</div>
        @if ($s['terms'] ?? null) <div style="margin-top: 3px;">{{ $s['terms'] }}</div> @endif
        @if ($verifyUrl) <div style="margin-top: 3px;">Verify this invoice: {{ $verifyUrl }}</div> @endif
        <div style="margin-top: 3px;">Booked on Trans EV (transevrecharge.com), operated by Tranzacta Private Limited.</div>
    </div>
</body>
</html>

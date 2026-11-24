@php
    $s = $invoice->supplier; $r = $invoice->recipient; $site = $invoice->site;
    $tz = \App\Models\Station::TIMEZONE;
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $half = rtrim(rtrim(number_format((float) $doc->gst_rate / 2, 2), '0'), '.');
    $isTest = optional($invoice->payment)->environment === 'simulator';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $doc->title() }} {{ $doc->number }}</title>
    @include('documents._styles')
</head>
<body>
    @if ($isTest)
        <div class="watermark">TEST DOCUMENT (simulated payment): not valid for tax purposes</div>
    @endif

    <table>
        <tr>
            <td style="width: 58%; vertical-align: top;">
                <div class="brand">TRANS <span>EV</span></div>
                <div class="bold" style="margin-top: 6px; font-size: 11px;">{{ $s['legal_name'] }}</div>
                <div class="muted">{{ $s['address'] }}</div>
                <div><span class="bold">GSTIN:</span> <span class="mono">{{ $doc->supplier_gstin }}</span>
                    @if ($s['pan'] ?? null) &nbsp;·&nbsp; <span class="bold">PAN:</span> <span class="mono">{{ $s['pan'] }}</span> @endif</div>
                <div><span class="bold">State:</span> {{ $s['state'] }} ({{ substr($doc->supplier_gstin, 0, 2) }})</div>
            </td>
            <td style="width: 42%; vertical-align: top;" class="right">
                <h1>{{ strtoupper($doc->title()) }}</h1>
                <div class="muted small">Issued under section 34 of the CGST Act</div>
                <table style="margin-top: 8px;">
                    <tr><td class="muted right">{{ $doc->title() }} no.</td><td class="right bold mono">{{ $doc->number }}</td></tr>
                    <tr><td class="muted right">Date</td><td class="right">{{ $doc->issued_at->setTimezone($tz)->format('d M Y') }}</td></tr>
                    <tr><td class="muted right">Against invoice</td><td class="right mono">{{ $invoice->number }}</td></tr>
                    <tr><td class="muted right">Invoice date</td><td class="right">{{ $invoice->issued_at->setTimezone($tz)->format('d M Y') }}</td></tr>
                    <tr><td class="muted right">Place of supply</td><td class="right">{{ \App\Support\IndianStates::name($doc->place_of_supply) }} ({{ $doc->place_of_supply }})</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 12px;">
        <tr>
            <td class="box" style="width: 50%;">
                <div class="label">Issued to</div>
                <div class="bold" style="font-size: 10.5px;">{{ $r['name'] }}</div>
                @if ($doc->recipient_gstin) <div><span class="bold">GSTIN:</span> <span class="mono">{{ $doc->recipient_gstin }}</span></div> @endif
                <div class="muted">{{ $r['email'] }}</div>
            </td>
            <td style="width: 2%;"></td>
            <td class="box" style="width: 48%;">
                <div class="label">Reason</div>
                <div>{{ $doc->reason }}</div>
                <div class="muted" style="margin-top: 4px;">Booking <span class="mono">{{ $site['booking_code'] }}</span> · {{ $site['station'] }}</div>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 12px;">
        <thead>
            <tr><th>Description</th><th style="width: 10%;">SAC</th><th style="width: 16%;" class="right">Taxable value</th>
                @if ((float) $doc->igst > 0) <th style="width: 14%;" class="right">IGST</th> @else <th style="width: 13%;" class="right">CGST {{ $half }}%</th><th style="width: 13%;" class="right">SGST {{ $half }}%</th> @endif
                <th style="width: 14%;" class="right">Total</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Reduction in value of EV charging service (refund)</td>
                <td class="mono">{{ $invoice->sac_code }}</td>
                <td class="right">{{ $money($doc->taxable_amount) }}</td>
                @if ((float) $doc->igst > 0) <td class="right">{{ $money($doc->igst) }}</td> @else <td class="right">{{ $money($doc->cgst) }}</td><td class="right">{{ $money($doc->sgst) }}</td> @endif
                <td class="right bold">{{ $money($doc->total) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="margin-top: 10px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <span class="label">Amount in words</span><br><span class="bold">{{ $doc->amount_in_words }}</span>
            </td>
            <td style="width: 45%; vertical-align: top;">
                <div class="stamp">
                    <div class="small muted">For {{ $s['legal_name'] }}</div>
                    <div style="margin-top: 16px;" class="bold">Authorised signatory</div>
                    <div class="small muted">Electronically generated · fingerprint <span class="mono">{{ substr($doc->content_hash, 0, 16) }}</span></div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer small muted">@if ($doc->isDebit())This debit note increases the taxable value and GST of the invoice above for the extra energy delivered.@else This credit note reduces the taxable value and GST of the invoice above. The refunded amount is credited to the original payment method.@endif</div>
</body>
</html>

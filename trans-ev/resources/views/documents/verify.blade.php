<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Invoice verification · Trans EV</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, sans-serif; background: #F6F9FC; color: #0B1B2B; }
        main { max-width: 480px; margin: 48px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 10px 30px rgba(11, 27, 43, .08); }
        .ok { color: #047857; } .bad { color: #B91C1C; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; margin: 20px 0 0; }
        dt { color: #5B6B7B; } dd { margin: 0; font-weight: 600; }
        @media (prefers-color-scheme: dark) { body { background: #0B1B2B; color: #E6EDF3; } .card { background: #12263A; } dt { color: #9FB3C8; } }
    </style>
</head>
<body>
<main>
    <div class="card">
        <p style="font-weight:800;font-size:20px;margin:0">TRANS <span style="color:#0077FF">EV</span></p>
        @if ($invoice)
            <h1 class="ok" style="font-size:20px">✓ Genuine invoice</h1>
            <p>This tax invoice was issued through Trans EV and has not been altered.</p>
            <dl>
                <dt>Invoice no.</dt><dd>{{ $invoice->number }}</dd>
                <dt>Date</dt><dd>{{ $invoice->issued_at->setTimezone(\App\Models\Station::TIMEZONE)->format('d M Y') }}</dd>
                <dt>Supplier</dt><dd>{{ $invoice->supplier['legal_name'] }}</dd>
                <dt>GSTIN</dt><dd>{{ $invoice->supplier_gstin }}</dd>
                <dt>Total</dt><dd>₹{{ number_format((float) $invoice->total, 2) }}</dd>
            </dl>
        @else
            <h1 class="bad" style="font-size:20px">✗ Could not verify</h1>
            <p>The verification link is incomplete or does not match an invoice issued by Trans EV. Contact support@transevrecharge.com.</p>
        @endif
    </div>
</main>
</body>
</html>

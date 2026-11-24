@php
    $b = $payment->booking;
    $paid = $payment->status === \App\Models\Payment::SUCCESS && $b->settlement === 'paid';
    $failed = $payment->status === \App\Models\Payment::FAILED;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $paid ? 'Payment received' : ($failed ? 'Payment not completed' : 'Confirming payment') }} · {{ config('app.name') }}</title>
    @unless ($paid || $failed)
        <meta http-equiv="refresh" content="5">
    @endunless
    <style>
        :root { --bg: #f8fafc; --card: #fff; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --ok: #059669; --bad: #dc2626; }
        @media (prefers-color-scheme: dark) { :root { --bg: #020617; --card: #0f172a; --ink: #f1f5f9; --muted: #94a3b8; --line: #1e293b; --ok: #34d399; --bad: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 24px; padding: 28px; text-align: center; }
        .icon { width: 64px; height: 64px; margin: 0 auto 12px; border-radius: 50%; display: grid; place-items: center; font-size: 32px; }
        .ok { background: color-mix(in srgb, var(--ok) 15%, transparent); color: var(--ok); }
        .bad { background: color-mix(in srgb, var(--bad) 15%, transparent); color: var(--bad); }
        .wait { background: color-mix(in srgb, var(--muted) 15%, transparent); color: var(--muted); }
        h1 { margin: 0 0 6px; font-size: 22px; }
        p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.5; }
        dl { margin: 20px 0 0; text-align: left; border-top: 1px solid var(--line); }
        dl div { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
        dt { color: var(--muted); } dd { margin: 0; font-weight: 600; text-align: right; }
        a.btn { display: inline-block; margin-top: 20px; padding: 12px 22px; border-radius: 999px; background: var(--ink); color: var(--card); text-decoration: none; font-weight: 600; }
        img.logo { height: 32px; margin-bottom: 18px; }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="/brand/logo-mark.svg" alt="{{ config('app.name') }}">
        @if ($paid)
            <div class="icon ok" aria-hidden="true">✓</div>
            <h1>Payment received</h1>
            <p>Thank you for charging with us. You can show this screen to the station staff.</p>
        @elseif ($failed)
            <div class="icon bad" aria-hidden="true">!</div>
            <h1>Payment not completed</h1>
            <p>No money was taken. Please ask the staff for a new payment link, or pay by cash or UPI.</p>
        @else
            <div class="icon wait" aria-hidden="true">…</div>
            <h1>Confirming your payment</h1>
            <p>We are checking with the bank. This page updates by itself.</p>
        @endif
        <dl>
            <div><dt>Station</dt><dd>{{ $b->station->name }}</dd></div>
            <div><dt>Charging</dt><dd>{{ $b->code }}</dd></div>
            <div><dt>Energy</dt><dd>{{ number_format((float) $b->est_kwh, 2) }} kWh</dd></div>
            <div><dt>Amount</dt><dd>₹{{ number_format((float) ($payment->paid_amount ?? $payment->amount), 2) }}</dd></div>
            @if ($payment->gateway_txn_id)
                <div><dt>Transaction</dt><dd>{{ $payment->gateway_txn_id }}</dd></div>
            @endif
        </dl>
        @if ($paid && $b->invoice)
            <a class="btn" href="{{ $invoiceUrl }}">Download GST invoice</a>
        @endif
    </main>
</body>
</html>

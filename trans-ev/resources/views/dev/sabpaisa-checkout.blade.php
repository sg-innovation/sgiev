<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Payment simulator (local only)</title>
    <style>
        :root { color-scheme: light dark; --bg:#f1f5f9; --card:#fff; --ink:#0b1b2b; --muted:#64748b; --line:#e2e8f0; }
        @media (prefers-color-scheme: dark) { :root { --bg:#070f1a; --card:#0e1b2b; --ink:#f1f5f9; --muted:#94a3b8; --line:#1e2d40; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink); font:15px/1.5 Inter, system-ui, sans-serif; padding:16px; }
        .card { width:100%; max-width:420px; background:var(--card); border:1px solid var(--line); border-radius:24px; padding:28px; box-shadow:0 20px 50px rgb(0 0 0 / .08); }
        .warn { background:#fef3c7; color:#78350f; border-radius:12px; padding:10px 12px; font-size:13px; font-weight:600; margin-bottom:20px; }
        .muted { color:var(--muted); font-size:13px; }
        .amount { font-size:40px; font-weight:800; margin:6px 0 20px; }
        dl { display:grid; grid-template-columns:auto 1fr; gap:6px 12px; font-size:13px; margin:0 0 24px; }
        dt { color:var(--muted); } dd { margin:0; text-align:right; font-weight:600; word-break:break-all; }
        button { width:100%; border:0; border-radius:12px; padding:13px; font-weight:700; font-size:15px; cursor:pointer; margin-top:10px; }
        .ok { background:linear-gradient(90deg,#00b386,#0077ff); color:#fff; }
        .fail { background:#fee2e2; color:#991b1b; }
        .cancel { background:transparent; color:var(--muted); border:1px solid var(--line); }
    </style>
</head>
<body>
<main class="card">
    <div class="warn">⚠ Local payment simulator. No real money moves. This page exists only when SABPAISA_SIMULATE=true in a local environment.</div>
    <div class="muted">Paying {{ $payment->booking?->station?->name ?? 'Trans EV (wallet recharge)' }}</div>
    <div class="amount">₹{{ number_format((float) $payment->amount, 2) }}</div>
    <dl>
        <dt>{{ $payment->booking ? 'Booking' : 'Reference' }}</dt><dd>{{ $payment->booking?->code ?? $payment->merchant_txn_id }}</dd>
        <dt>Merchant txn</dt><dd>{{ $payment->merchant_txn_id }}</dd>
    </dl>
    @foreach (['SUCCESS' => ['ok', 'Simulate successful payment'], 'FAILED' => ['fail', 'Simulate failed payment'], 'CANCELLED' => ['cancel', 'Cancel and go back']] as $status => [$class, $label])
        <form method="POST" action="{{ route('dev.sabpaisa.complete', ['payment' => $payment->uuid]) }}">
            @csrf
            <input type="hidden" name="status" value="{{ $status }}">
            <button class="{{ $class }}" type="submit">{{ $label }}</button>
        </form>
    @endforeach
</main>
</body>
</html>

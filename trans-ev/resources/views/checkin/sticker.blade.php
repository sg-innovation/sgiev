<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR sticker · {{ $charger->station->name }} · {{ $charger->code }}</title>
    <style>
        :root { --ink: #0f172a; --muted: #475569; --brand: #16a34a; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #f1f5f9; }
        .bar { display: flex; gap: 12px; align-items: center; justify-content: center; padding: 16px; }
        .bar button { font: inherit; font-weight: 600; padding: 10px 20px; border-radius: 999px; border: 0; background: var(--ink); color: #fff; cursor: pointer; }
        .bar p { margin: 0; color: var(--muted); font-size: 14px; }
        .sticker { width: 100mm; margin: 12px auto 40px; background: #fff; border-radius: 6mm; overflow: hidden; border: 1px solid #e2e8f0; }
        .top { background: linear-gradient(135deg, #0f172a, #064e3b); color: #fff; padding: 6mm; text-align: center; }
        .top h1 { margin: 0; font-size: 7mm; letter-spacing: .02em; }
        .top p { margin: 1.5mm 0 0; font-size: 3.4mm; opacity: .85; }
        .qr { padding: 6mm 10mm 3mm; }
        .qr img { width: 100%; display: block; image-rendering: pixelated; }
        .code { text-align: center; padding: 0 6mm 6mm; }
        .code .big { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 800; font-size: 9mm; letter-spacing: .08em; }
        .code .meta { font-size: 3.4mm; color: var(--muted); margin-top: 1mm; }
        .steps { border-top: 1px dashed #cbd5e1; padding: 4mm 6mm 6mm; font-size: 3.2mm; color: var(--muted); }
        .steps ol { margin: 0; padding-left: 5mm; }
        .url { font-size: 2.4mm; color: #94a3b8; word-break: break-all; margin-top: 2mm; }
        @media print { body { background: #fff; } .bar { display: none; } .sticker { margin: 0 auto; border: 1px solid #cbd5e1; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Print sticker</button>
        <p>100 mm wide. Stick it on the charger where drivers can scan it.</p>
    </div>
    <div class="sticker">
        <div class="top">
            <h1>Scan to check in</h1>
            <p>{{ $charger->station->company->trade_name ?? config('app.name') }} · {{ $charger->station->name }}</p>
        </div>
        <div class="qr"><img src="{{ $qr }}" alt="QR code for charger {{ $charger->code }}"></div>
        <div class="code">
            <div class="big">{{ $charger->code }}</div>
            <div class="meta">{{ $charger->type->value }} · {{ rtrim(rtrim(number_format((float) $charger->power_kw, 2), '0'), '.') }} kW · {{ $charger->connectorPoints->map(fn ($c) => '#'.$c->connector_no.' '.$c->type->getLabel())->implode(' · ') }}</div>
        </div>
        <div class="steps">
            <ol>
                <li>Open your phone camera and scan this code.</li>
                <li>Sign in and tap <strong>Check in &amp; start</strong> on your booking.</li>
                <li>Plug in and start charging.</li>
            </ol>
            <div class="url">{{ $url }}</div>
        </div>
    </div>
</body>
</html>

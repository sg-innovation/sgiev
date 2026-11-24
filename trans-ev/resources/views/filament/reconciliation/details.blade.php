@php
    $rows = array_filter([
        'Problem' => $ex->summary,
        'Severity' => \App\Models\ReconciliationException::SEVERITIES[$ex->severity] ?? $ex->severity,
        'Operator' => $ex->company?->trade_name,
        'Booking' => $ex->booking?->code,
        'Payment' => $ex->payment ? $ex->payment->merchant_txn_id.' · '.ucfirst($ex->payment->status).($ex->payment->gateway_txn_id ? ' · gateway '.$ex->payment->gateway_txn_id : '') : null,
        'Refund' => $ex->refund ? '₹'.number_format((float) $ex->refund->amount, 2).' · '.ucfirst($ex->refund->status) : null,
        'Expected' => $ex->expected_amount !== null ? '₹'.number_format((float) $ex->expected_amount, 2) : null,
        'Found' => $ex->actual_amount !== null ? '₹'.number_format((float) $ex->actual_amount, 2) : null,
        'First seen' => $ex->first_seen_at->timezone('Asia/Kolkata')->format('d M Y, h:i A'),
        'Last seen' => $ex->last_seen_at->timezone('Asia/Kolkata')->format('d M Y, h:i A').' · '.$ex->occurrences.' run(s)',
        'Assigned to' => $ex->assignee?->name,
        'Closed' => $ex->resolved_at ? $ex->resolved_at->timezone('Asia/Kolkata')->format('d M Y, h:i A').($ex->resolver ? ' by '.$ex->resolver->name : '') : null,
        'Note' => $ex->resolution_note,
    ], fn ($v) => $v !== null && $v !== '');
@endphp
<dl class="divide-y divide-gray-100 text-sm dark:divide-white/5">
    @foreach ($rows as $label => $value)
        <div class="grid grid-cols-3 gap-3 py-2">
            <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
            <dd class="col-span-2 whitespace-pre-line text-gray-900 dark:text-gray-100">{{ $value }}</dd>
        </div>
    @endforeach
    @foreach ($ex->details ?? [] as $k => $v)
        <div class="grid grid-cols-3 gap-3 py-2">
            <dt class="text-gray-500 dark:text-gray-400">{{ str($k)->headline() }}</dt>
            <dd class="col-span-2 font-mono text-xs text-gray-900 dark:text-gray-100">{{ is_bool($v) ? ($v ? 'Yes' : 'No') : (is_scalar($v) ? $v : json_encode($v)) }}</dd>
        </div>
    @endforeach
</dl>

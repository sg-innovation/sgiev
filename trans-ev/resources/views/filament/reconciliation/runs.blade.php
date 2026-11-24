<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <th class="py-2 pe-3">Started</th><th class="py-2 pe-3">By</th><th class="py-2 pe-3 text-end">Gateway checks</th>
                <th class="py-2 pe-3 text-end">New</th><th class="py-2 pe-3 text-end">Cleared</th><th class="py-2 pe-3 text-end">Open</th><th class="py-2">Previous day</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($runs as $run)
                <tr class="text-gray-700 dark:text-gray-200">
                    <td class="py-2 pe-3 whitespace-nowrap">{{ $run->started_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }}</td>
                    <td class="py-2 pe-3">{{ $run->triggeredBy?->name ?? 'Schedule' }}</td>
                    <td class="py-2 pe-3 text-end tabular-nums">{{ $run->stats['enquiries'] ?? 0 }}@if (($run->stats['enquiry_errors'] ?? 0) > 0) <span class="text-danger-600">({{ $run->stats['enquiry_errors'] }} failed)</span>@endif</td>
                    <td class="py-2 pe-3 text-end tabular-nums">{{ $run->new_exceptions }}</td>
                    <td class="py-2 pe-3 text-end tabular-nums">{{ $run->cleared_exceptions }}</td>
                    <td class="py-2 pe-3 text-end tabular-nums">{{ $run->open_exceptions }}</td>
                    <td class="py-2 whitespace-nowrap">
                        @if ($run->error)
                            <span class="text-danger-600 dark:text-danger-400">Failed: {{ \Illuminate\Support\Str::limit($run->error, 60) }}</span>
                        @else
                            ₹{{ number_format((float) ($run->stats['collected'] ?? 0), 2) }} from {{ $run->stats['collected_count'] ?? 0 }} payments · ₹{{ number_format((float) ($run->stats['refunded'] ?? 0), 2) }} refunded
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-6 text-center text-gray-500">No runs yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

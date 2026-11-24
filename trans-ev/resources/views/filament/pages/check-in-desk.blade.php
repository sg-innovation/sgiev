<x-filament-panels::page>
    @php $s = $this->stats(); @endphp

    <div class="grid gap-4 lg:grid-cols-2">
        <form wire:submit="submitScan" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
              x-data x-on:scan-done.window="$refs.scan.focus()">
            <label for="checkin-scan" class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                <x-filament::icon icon="heroicon-o-qr-code" class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                Scan QR or enter booking code
            </label>
            <div class="mt-3 flex gap-2">
                <input id="checkin-scan" x-ref="scan" wire:model="scan" autofocus autocomplete="off" placeholder="TEV-261124-ABCDE or scanned link"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                <x-filament::button type="submit" icon="heroicon-m-check-circle" class="shrink-0 whitespace-nowrap">Check in</x-filament::button>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                A handheld scanner types the link and presses Enter. On a phone, scanning the customer’s QR with the camera opens the check-in page directly.
            </p>
        </form>

        <div class="grid grid-cols-2 gap-4">
            @foreach ([
                ['Expected today', $s['expected'], 'heroicon-o-calendar-days', 'text-gray-400'],
                ['Checked in', $s['checked'], 'heroicon-o-check-badge', 'text-success-500'],
                ['Still to arrive', $s['waiting'], 'heroicon-o-clock', 'text-primary-500'],
                ['Late now', $s['late'], 'heroicon-o-exclamation-triangle', $s['late'] ? 'text-warning-500' : 'text-gray-400'],
            ] as [$label, $value, $icon, $tone])
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <x-filament::icon :icon="$icon" :class="'h-4 w-4 '.$tone" /> {{ $label }}
                    </div>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>

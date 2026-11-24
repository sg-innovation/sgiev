<x-filament-panels::page>
    @php $d = $this->summary(); @endphp

    @if (session('success'))
        <div class="rounded-xl bg-success-50 p-4 text-sm text-success-800 ring-1 ring-success-200 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-400/20">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-800 ring-1 ring-danger-200 dark:bg-danger-500/10 dark:text-danger-200 dark:ring-danger-400/20">{{ session('error') }}</div>
    @endif

    @if ($this::manager())
        <div class="flex flex-wrap items-center gap-3">
            <label for="wallet-operator" class="text-sm font-medium text-gray-700 dark:text-gray-200">Operator</label>
            <select id="wallet-operator" wire:model.live="company" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                @foreach ($d['operators'] ?? [] as $op)
                    <option value="{{ $op->id }}">{{ $op->trade_name }}{{ $op->billing_mode === 'wallet' ? '' : ' (monthly invoice)' }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if (! empty($d))
        @if ($d['mode'] !== 'wallet')
            <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                {{ $d['company']->trade_name }} is billed by <strong>monthly invoice</strong>, so the wallet is not used to accept bookings. Platform fees appear under Platform fees.
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Balance', $d['balance'], 'heroicon-o-wallet', $d['balance'] < (float) $d['wallet']->low_balance_alert ? 'danger' : 'success'],
                ['Held for unpaid bookings', $d['reserved'], 'heroicon-o-clock', 'gray'],
                ['Available for new bookings', $d['available'], 'heroicon-o-check-badge', $d['available'] > $d['sample'] ? 'success' : 'danger'],
                ['Platform fees this month', $d['month'], 'heroicon-o-receipt-percent', 'warning'],
            ] as [$label, $value, $icon, $tone])
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <x-filament::icon :icon="$icon" :class="\Illuminate\Support\Arr::toCssClasses(['h-5 w-5', 'text-success-500' => $tone === 'success', 'text-danger-500' => $tone === 'danger', 'text-warning-500' => $tone === 'warning', 'text-gray-400' => $tone === 'gray'])" /> {{ $label }}
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">₹{{ number_format($value, 2) }}</p>
                </div>
            @endforeach
        </div>

        <div class="flex items-start gap-3 rounded-xl bg-primary-50 p-4 text-sm text-primary-900 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-100 dark:ring-primary-400/20">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" />
            <p>
                Platform fee: <strong>{{ $d['rates'] }}</strong>, plus GST. For example, a ₹500 booking (before GST) needs about
                <strong>₹{{ number_format($d['sample'], 2) }}</strong> in the wallet. Customers can book your stations only while the available balance covers
                the fee; it is taken when the customer pays and returned in proportion if the customer is refunded. Discounts funded by Trans EV coupons are
                credited automatically.
            </p>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>

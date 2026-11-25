<x-filament-panels::page>
    @php
        $tabs = $this->tabs();
        $data = $this->report();
        $f = $this->filters();
        $inr = fn ($v, $d = 0) => '₹'.\App\Support\IndianNumber::format($v, $d);
        $num = fn ($v, $d = 0) => \App\Support\IndianNumber::format($v, $d);
        $cell = fn (string $m, $v) => in_array($m, \App\Services\Reports\ReportService::COUNTS, true) ? $num($v) : ($m === 'kwh' ? $num($v, 1) : $inr($v));
        $card = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
        $th = 'whitespace-nowrap px-3 py-2 text-end text-xs font-medium text-gray-500 dark:text-gray-400';
        $td = 'whitespace-nowrap px-3 py-2 text-end tabular-nums text-gray-700 dark:text-gray-200';
        $input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-white/10 dark:bg-gray-900 dark:text-white';
        // Columns for breakdown tables (all metrics; overview adds the period column).
        $cols = \App\Services\Reports\ReportService::METRICS;
    @endphp

    <div class="space-y-6">
        {{-- Filters --}}
        <div class="{{ $card }} space-y-3 p-4">
            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">From
                    <input type="date" wire:model.live.debounce.500ms="from" max="{{ $to }}" class="{{ $input }}">
                </label>
                <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">To
                    <input type="date" wire:model.live.debounce.500ms="to" min="{{ $from }}" class="{{ $input }}">
                </label>
                <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">Group by
                    <select wire:model.live="group" class="{{ $input }}">
                        @foreach (\App\Services\Reports\ReportFilters::GROUPS as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($this->isPlatform())
                    <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">Operator
                        <select wire:model.live="company" class="{{ $input }} max-w-56">
                            <option value="">All operators</option>
                            @foreach ($this->companies() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if (count($franchiseOptions = $this->franchises()))
                    <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">Franchise
                        <select wire:model.live="franchise" class="{{ $input }} max-w-56">
                            <option value="">{{ $this->isPlatform() ? 'No franchise filter' : 'All my franchises' }}</option>
                            @foreach ($franchiseOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">Station
                    <select wire:model.live="station" class="{{ $input }} max-w-72">
                        <option value="">All my stations</option>
                        @foreach ($this->stations() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <div wire:loading.delay class="pb-2 text-xs text-gray-500 dark:text-gray-400">Updating…</div>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'last_month' => 'Last month', 'fy' => 'This financial year', 'last_fy' => 'Last financial year'] as $k => $label)
                    <button type="button" wire:click="preset('{{ $k }}')" class="rounded-full px-3 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-white/5">{{ $label }}</button>
                @endforeach
                <span class="ms-auto self-center text-xs text-gray-500 dark:text-gray-400">{{ $f->label() }} · {{ $f->scopeLabel() }}</span>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-white/10" role="tablist">
            @foreach ($tabs as $k => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $k ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $k }}')"
                    @class(['-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' => $tab === $k,
                        'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $k])>{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'overview')
            @php $s = $data['summary']; $rows = $data['rows']; $max = max(1, ...array_map(fn ($r) => (float) $r['net_collected'], $rows ?: [['net_collected' => 1]])); @endphp
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    ['Net collected', $inr($s['net_collected']), ($s['online_share'] !== null ? $s['online_share'].'% online' : 'No payments yet'), 'heroicon-o-banknotes'],
                    ['Net sales (excl. GST)', $inr($s['sales']), 'GST '.$inr($s['gst']), 'heroicon-o-document-text'],
                    ['Gross profit (est.)', $inr($s['gross_profit']), 'After electricity '.$inr($s['energy_cost']).' and fees '.$inr($s['platform_fees']), 'heroicon-o-arrow-trending-up'],
                    ['Bookings', $num($s['bookings'] + $s['walk_ins']), $num($s['walk_ins']).' walk-ins · '.$num($s['completed']).' completed', 'heroicon-o-calendar-days'],
                    ['Energy delivered', $num($s['kwh'], 1).' kWh', $num($s['sessions']).' sessions', 'heroicon-o-bolt'],
                    ['Cancellations & no-shows', $num($s['cancelled']).' · '.$num($s['no_shows']), 'Fees kept '.$inr((float) $s['cancellation_kept'] + (float) $s['no_show_kept']), 'heroicon-o-x-circle'],
                ] as [$label, $value, $sub, $icon])
                    <div class="{{ $card }} p-4">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><x-filament::icon :icon="$icon" class="h-4 w-4 text-gray-400" /> {{ $label }}</div>
                        <p class="mt-1 text-xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $value }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $sub }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Net collected per period: one series, so the title names it; hover any bar for the figures. --}}
            <div class="{{ $card }} p-4">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Net collected per {{ strtolower(\App\Services\Reports\ReportFilters::GROUPS[$f->group]) }}</h3>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Peak {{ $inr($max) }}</span>
                </div>
                <div class="flex h-44 items-end gap-[2px] border-b border-gray-200 dark:border-white/10" role="img" aria-label="Bar chart of net collected per period; figures are in the table below">
                    @foreach ($rows as $r)
                        @php $h = max(0, (float) $r['net_collected']) / $max * 100; @endphp
                        <div class="group relative flex h-full min-w-0 flex-1 items-end">
                            <div class="w-full rounded-t-[4px] bg-primary-500/85 transition-colors group-hover:bg-primary-600 dark:bg-primary-400/80 dark:group-hover:bg-primary-300" style="height: {{ max($h, (float) $r['net_collected'] > 0 ? 1.5 : 0) }}%"></div>
                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 hidden w-max -translate-x-1/2 rounded-lg bg-gray-950 px-2.5 py-1.5 text-xs text-white shadow-lg group-hover:block dark:bg-gray-700">
                                <div class="font-medium">{{ $r['label'] }}</div>
                                <div>Net collected {{ $inr($r['net_collected']) }}</div>
                                <div class="text-gray-300">{{ $num($r['bookings'] + $r['walk_ins']) }} bookings · {{ $num($r['kwh'], 1) }} kWh</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @php $every = max(1, (int) ceil(count($rows) / 8)); @endphp
                <div class="mt-1 flex gap-[2px] text-[10px] text-gray-500 dark:text-gray-400">
                    @foreach ($rows as $i => $r)
                        <div class="min-w-0 flex-1 overflow-visible whitespace-nowrap">{{ $i % $every === 0 ? \Illuminate\Support\Str::of($r['label'])->replace(['Week of ', 'Mon, ', 'Tue, ', 'Wed, ', 'Thu, ', 'Fri, ', 'Sat, ', 'Sun, '], '')->beforeLast(' 20') : '' }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (in_array($tab, ['overview', 'stations', 'chargers', 'operators'], true))
            @php $first = ['overview' => 'Period', 'stations' => 'Station', 'chargers' => 'Charger', 'operators' => 'Operator'][$tab]; @endphp
            <div class="{{ $card }} overflow-hidden">
                @if (! $data['rows'])
                    <p class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Nothing in this period.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="sticky left-0 z-10 whitespace-nowrap bg-gray-50 px-3 py-2 text-start text-xs font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $first }}</th>
                                    @foreach ($cols as $m => $label)
                                        <th class="{{ $th }}">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($data['rows'] as $r)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                        <td class="sticky left-0 max-w-[11rem] truncate whitespace-nowrap bg-white px-3 py-2 text-start font-medium text-gray-950 sm:max-w-xs dark:bg-gray-900 dark:text-white" title="{{ $r['label'] }}">{{ $r['label'] }}</td>
                                        @foreach ($cols as $m => $label)
                                            <td @class([$td, 'text-danger-600 dark:text-danger-400' => $m === 'gross_profit' && (float) $r[$m] < 0])>{{ $cell($m, $r[$m]) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
                                <tr>
                                    <td class="sticky left-0 bg-gray-50 px-3 py-2 text-start text-gray-950 dark:bg-gray-800 dark:text-white">Total</td>
                                    @foreach ($cols as $m => $label)
                                        <td class="{{ $td }} text-gray-950 dark:text-white">{{ $cell($m, $data['totals'][$m] ?? 0) }}</td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Bookings count by slot start; money by the time it was paid or refunded; sales and GST from tax invoices, credit and debit notes.
                Electricity cost uses the rates in <a href="{{ \App\Filament\Resources\EnergyCosts\EnergyCostResource::getUrl() }}" class="text-primary-600 underline dark:text-primary-400">Electricity costs</a>@if ($tab === 'chargers') (per-kWh part only)@endif;
                platform fees come from the Trans EV wallet. Profit is an estimate.
            </p>
        @endif

        @if ($tab === 'pending')
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($data as $key => $p)
                    <div class="{{ $card }} p-4">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $p['label'] }}</h3>
                            <span @class(['text-sm font-semibold tabular-nums', 'text-warning-600 dark:text-warning-400' => $p['count'] > 0, 'text-gray-500' => $p['count'] === 0])>{{ $p['count'] }} · {{ $inr($p['amount'], 2) }}</span>
                        </div>
                        @if ($p['items'])
                            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-white/5">
                                @foreach ($p['items'] as $it)
                                    <li class="flex items-center justify-between gap-3 py-1.5">
                                        <span class="min-w-0 truncate"><span class="font-mono text-xs text-gray-950 dark:text-white">{{ $it['code'] }}</span> <span class="text-gray-500 dark:text-gray-400">· {{ $it['station'] }} · {{ $it['since'] }}</span></span>
                                        <span class="tabular-nums text-gray-700 dark:text-gray-200">{{ $inr($it['amount'], 2) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nothing pending.</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">A snapshot of today, not limited to the dates above. Reconciliation has the full list with one-click fixes.</p>
        @endif

        @if ($tab === 'gst')
            <div class="{{ $card }} overflow-hidden">
                @if (! $data['rows'])
                    <p class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">No GST documents in this period.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Supplier GSTIN</th>
                                    @foreach (['Rate', 'Type', 'Invoices', 'Taxable', 'CGST', 'SGST', 'IGST', 'Credit notes', 'Credit taxable', 'Credit tax', 'Debit notes', 'Debit taxable', 'Debit tax', 'Net taxable', 'Net tax'] as $h)
                                        <th class="{{ $th }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($data['rows'] as $r)
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-950 dark:text-white">{{ $r['gstin'] }}</td>
                                        <td class="{{ $td }}">{{ rtrim(rtrim($r['rate'], '0'), '.') }}%</td>
                                        <td class="{{ $td }}">{{ $r['type'] }}</td>
                                        <td class="{{ $td }}">{{ $r['invoices'] }}</td>
                                        @foreach (['taxable', 'cgst', 'sgst', 'igst'] as $m)<td class="{{ $td }}">{{ $inr($r[$m], 2) }}</td>@endforeach
                                        <td class="{{ $td }}">{{ $r['credit_notes'] }}</td>
                                        <td class="{{ $td }}">{{ $inr($r['credit_taxable'], 2) }}</td>
                                        <td class="{{ $td }}">{{ $inr($r['credit_tax'], 2) }}</td>
                                        <td class="{{ $td }}">{{ $r['debit_notes'] }}</td>
                                        <td class="{{ $td }}">{{ $inr($r['debit_taxable'], 2) }}</td>
                                        <td class="{{ $td }}">{{ $inr($r['debit_tax'], 2) }}</td>
                                        <td class="{{ $td }} font-semibold">{{ $inr($r['net_taxable'], 2) }}</td>
                                        <td class="{{ $td }} font-semibold">{{ $inr($r['net_tax'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
                                @php $t = $data['totals']; @endphp
                                <tr>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white" colspan="3">Total</td>
                                    <td class="{{ $td }}">{{ $t['invoices'] }}</td>
                                    @foreach (['taxable', 'cgst', 'sgst', 'igst'] as $m)<td class="{{ $td }}">{{ $inr($t[$m], 2) }}</td>@endforeach
                                    <td class="{{ $td }}">{{ $t['credit_notes'] }}</td>
                                    <td class="{{ $td }}">{{ $inr($t['credit_taxable'], 2) }}</td>
                                    <td class="{{ $td }}">{{ $inr($t['credit_tax'], 2) }}</td>
                                    <td class="{{ $td }}">{{ $t['debit_notes'] }}</td>
                                    <td class="{{ $td }}">{{ $inr($t['debit_taxable'], 2) }}</td>
                                    <td class="{{ $td }}">{{ $inr($t['debit_tax'], 2) }}</td>
                                    <td class="{{ $td }}">{{ $inr($t['net_taxable'], 2) }}</td>
                                    <td class="{{ $td }}">{{ $inr($t['net_tax'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">By issue date. For return filing use Billing &amp; GST → Tax invoices → GSTR-1 export, which has the exact GSTR-1 tables.</p>
        @endif

        @if ($tab === 'fees')
            <div class="{{ $card }} overflow-hidden">
                <h3 class="px-4 pt-4 text-sm font-semibold text-gray-950 dark:text-white">Platform-fee statements</h3>
                @if (! $data['statements'])
                    <p class="p-6 text-sm text-gray-500 dark:text-gray-400">No statements for months starting in this period.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                                @foreach (['Operator', 'Number', 'Month', 'Status'] as $h)<th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ $h }}</th>@endforeach
                                @foreach (['Fees', 'GST', 'Total', 'Coupon credit', 'Net payable', 'Paid', 'Outstanding'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($data['statements'] as $r)
                                    <tr>
                                        <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $r['company'] }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $r['number'] }}</td>
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $r['period'] }}</td>
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ ucfirst($r['status']) }}</td>
                                        @foreach (['taxable', 'gst', 'total', 'reimbursement', 'net_payable', 'paid', 'outstanding'] as $m)<td @class([$td, 'text-warning-600 dark:text-warning-400 font-semibold' => $m === 'outstanding' && (float) $r[$m] > 0])>{{ $inr($r[$m], 2) }}</td>@endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold dark:bg-white/5"><tr>
                                <td class="px-3 py-2 text-gray-950 dark:text-white" colspan="6">Total</td>
                                <td class="{{ $td }}">{{ $inr($data['statement_totals']['total'], 2) }}</td><td class="{{ $td }}"></td>
                                <td class="{{ $td }}">{{ $inr($data['statement_totals']['net_payable'], 2) }}</td>
                                <td class="{{ $td }}">{{ $inr($data['statement_totals']['paid'], 2) }}</td>
                                <td class="{{ $td }}">{{ $inr($data['statement_totals']['outstanding'], 2) }}</td>
                            </tr></tfoot>
                        </table>
                    </div>
                @endif
            </div>
            <div class="{{ $card }} overflow-hidden">
                <h3 class="px-4 pt-4 text-sm font-semibold text-gray-950 dark:text-white">Trans EV wallets</h3>
                <div class="overflow-x-auto">
                    <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5"><tr>
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Operator</th>
                            @foreach (['Fees charged in period', 'Funds added in period', 'Coupon credits', 'Balance now'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($data['wallets'] as $w)
                                <tr>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $w['company'] }} @if ($w['low'])<span class="ms-1 rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">Low balance</span>@endif</td>
                                    @foreach (['fees', 'added', 'coupon_credits', 'balance'] as $m)<td class="{{ $td }}">{{ $inr($w[$m], 2) }}</td>@endforeach
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">No wallets.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

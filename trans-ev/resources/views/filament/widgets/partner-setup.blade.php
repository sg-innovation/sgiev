<x-filament-widgets::widget>
    @php
        $d = $this->getData();
        $total = count($d['steps']);
        $pct = (int) round($d['done'] / max(1, $total) * 100);
    @endphp
    <div class="grid gap-4 lg:grid-cols-3">
        @if ($d['done'] < $total)
            <x-filament::section class="lg:col-span-2">
                <x-slot name="heading">Go-live checklist</x-slot>
                <x-slot name="description">{{ $d['done'] }} of {{ $total }} done. Customers can book once every step is complete.</x-slot>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-primary-500" style="width: {{ $pct }}%"></div>
                </div>
                <ol class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($d['steps'] as [$label, $ok, $url])
                        <li>
                            <a href="{{ $url }}" class="flex items-center gap-2.5 rounded-lg p-2 text-sm transition hover:bg-gray-50 dark:hover:bg-white/5">
                                @if ($ok)
                                    <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 shrink-0 text-success-500" />
                                    <span class="text-gray-500 line-through dark:text-gray-400">{{ $label }}</span>
                                @else
                                    <span class="h-5 w-5 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600"></span>
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $label }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endif

        <x-filament::section class="{{ $d['done'] < $total ? '' : 'lg:col-span-3' }}">
            <x-slot name="heading">
                <span class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5 text-primary-500" />
                    {{ $d['plan']?->name ?? 'Free' }} plan
                </span>
            </x-slot>
            <x-slot name="description">
                {{ $d['plan'] && (float) $d['plan']->price_monthly > 0 ? '₹'.number_format((float) $d['plan']->price_monthly).' / month' : 'No monthly fee' }}
            </x-slot>
            <div class="text-sm">
                <div class="mb-3 rounded-lg bg-gray-50 p-2.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    <span class="font-semibold text-gray-950 dark:text-white">Platform fees:</span> {{ $d['fees'] }}
                </div>
                <div class="flex justify-between text-gray-500 dark:text-gray-400">
                    <span>Stations</span>
                    <span class="font-semibold text-gray-950 dark:text-white">{{ $d['stationsUsed'] }} / {{ $d['plan']?->max_stations ?? '∞' }}</span>
                </div>
                @if ($d['plan']?->max_stations)
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full {{ $d['stationsUsed'] >= $d['plan']->max_stations ? 'bg-danger-500' : 'bg-primary-500' }}" style="width: {{ min(100, (int) round($d['stationsUsed'] / $d['plan']->max_stations * 100)) }}%"></div>
                    </div>
                @endif
                <ul class="mt-4 space-y-1.5">
                    @foreach ($d['premium'] as $f)
                        <li class="flex items-center gap-2 {{ $f['on'] ? 'text-gray-800 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500' }}">
                            <x-filament::icon :icon="$f['on'] ? 'heroicon-m-check' : 'heroicon-m-lock-closed'" class="h-4 w-4 shrink-0 {{ $f['on'] ? 'text-success-500' : '' }}" />
                            {{ $f['label'] }}
                        </li>
                    @endforeach
                </ul>
                @if ($d['premium']->contains('on', false))
                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Locked features come with Premium. Contact support@transevrecharge.com to upgrade.</p>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>

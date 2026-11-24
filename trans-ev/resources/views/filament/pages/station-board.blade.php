<x-filament-panels::page>
    @php
        $stations = $this->stations();
        $d = $this->board();
        $toneRing = [
            'available' => 'ring-success-500/40', 'charging' => 'ring-warning-500/50', 'reserved' => 'ring-info-500/40', 'down' => 'ring-danger-500/50',
        ];
        $chip = fn (string $state) => match ($state) {
            'available' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
            'charging' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300',
            'reserved' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300',
            default => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300',
        };
        $stateLabel = ['available' => 'Available', 'charging' => 'Charging', 'reserved' => 'Booked now', 'down' => 'Out of service'];
    @endphp

    <div wire:poll.15s class="space-y-6">
        <div class="flex flex-wrap items-center gap-3">
            @if (count($stations) > 1)
                <label for="board-station" class="text-sm font-medium text-gray-700 dark:text-gray-200">Station</label>
                <select id="board-station" wire:model.live="station" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                    @foreach ($stations as $s)
                        <option value="{{ $s['id'] }}">{{ $s['label'] }}</option>
                    @endforeach
                </select>
            @endif
            @if ($d)
                <span class="ms-auto inline-flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-success-500"></span></span>
                    Live · updated {{ $d['updatedAt'] }}
                </span>
            @endif
        </div>

        @if (! $d)
            <div class="rounded-xl bg-white p-8 text-center text-sm text-gray-500 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
                You are not assigned to any station yet.
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                @foreach ([
                    ['Available', $d['counts']['available'], 'heroicon-o-check-circle', 'text-success-500'],
                    ['Charging', $d['counts']['charging'], 'heroicon-o-bolt', 'text-warning-500'],
                    ['Booked now', $d['counts']['reserved'], 'heroicon-o-clock', 'text-info-500'],
                    ['Out of service', $d['counts']['down'], 'heroicon-o-exclamation-triangle', $d['counts']['down'] ? 'text-danger-500' : 'text-gray-400'],
                    ['Bookings today', $d['today']['bookings'], 'heroicon-o-calendar-days', 'text-gray-400'],
                    ['Sessions today', $d['today']['sessions'], 'heroicon-o-play-circle', 'text-gray-400'],
                    ['kWh delivered today', $d['today']['energy'], 'heroicon-o-battery-100', 'text-gray-400'],
                ] as [$label, $value, $icon, $tone])
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon :icon="$icon" :class="'h-4 w-4 '.$tone" /> {{ $label }}
                        </div>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                @foreach ($d['chargers'] as $c)
                    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-white/5">
                            <h2 class="flex items-center gap-2 font-semibold text-gray-950 dark:text-white">
                                <x-filament::icon icon="heroicon-o-bolt" class="h-5 w-5 text-primary-500" />
                                <span class="font-mono">{{ $c['code'] }}</span>
                            </h2>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $c['type'] }} · {{ $c['powerKw'] }} kW</span>
                        </header>
                        <div class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($c['connectors'] as $k)
                                <div class="space-y-3 p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="font-medium text-gray-950 dark:text-white">#{{ $k['no'] }} · {{ $k['type'] }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $k['enabled'] ? $k['live'] : 'Disabled by admin' }}{{ $k['since'] ? ' since '.$k['since'] : '' }}{{ $k['note'] ? ' · “'.$k['note'].'”' : '' }}
                                            </p>
                                        </div>
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $chip($k['state']) }}">{{ $stateLabel[$k['state']] }}</span>
                                    </div>

                                    @if ($k['booking'])
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                            <span class="rounded-md border border-gray-200 bg-gray-50 px-1.5 font-mono font-bold tracking-wider text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $k['booking']['registration'] ?? '—' }}</span>
                                            <span class="text-gray-700 dark:text-gray-200">{{ $k['booking']['customer'] }}</span>
                                            <span class="text-gray-500 dark:text-gray-400">{{ $k['booking']['vehicle'] }}</span>
                                            <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $k['booking']['code']]) }}" class="font-mono text-xs text-primary-600 hover:underline dark:text-primary-400">{{ $k['booking']['code'] }}</a>
                                            @if ($k['booking']['source'] === 'walk_in')
                                                <span class="rounded-full bg-info-50 px-2 text-xs font-semibold text-info-700 dark:bg-info-500/10 dark:text-info-300">Walk-in</span>
                                            @endif
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            Slot {{ $k['booking']['slot'] }} · {{ $k['booking']['status'] }}
                                            @if ($k['booking']['late'])
                                                · <span class="font-semibold text-danger-600 dark:text-danger-400">not arrived yet</span>
                                            @endif
                                        </p>
                                    @endif

                                    @if ($k['session'])
                                        @php $s = $k['session']; @endphp
                                        <div>
                                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                                <span class="font-semibold tabular-nums text-gray-950 dark:text-white">
                                                    {{ number_format($s['energy'], 2) }}@if ($s['target']) / {{ number_format($s['target'], 1) }}@endif kWh
                                                    @unless ($s['measured'])
                                                        <span class="text-xs font-normal text-gray-500">(estimated)</span>
                                                    @endunless
                                                </span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    since {{ $s['startedAt'] }} · {{ $s['minutes'] }} min · {{ $s['power'] }} kW{{ $s['powerMeasured'] ? '' : ' est.' }}{{ $s['soc'] !== null ? ' · '.$s['soc'].'%' : '' }}
                                                </span>
                                            </div>
                                            @if ($s['progress'] !== null)
                                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $s['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Charging progress">
                                                    <div class="h-full rounded-full bg-warning-500" style="width: {{ $s['progress'] }}%"></div>
                                                </div>
                                            @endif
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ $s['finishAt'] ? 'Estimated finish '.$s['finishAt'] : '' }}
                                                {{ $s['readingAt'] ? '· last reading '.$s['readingAt'] : '· no readings yet' }}
                                            </p>
                                        </div>
                                    @endif

                                    <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/5">
                                        <span class="text-gray-600 dark:text-gray-300">
                                            @if ($k['state'] === 'down')
                                                Out of service
                                            @elseif ($k['freeAt'] === 'now')
                                                <strong class="text-success-700 dark:text-success-300">Free now</strong>
                                            @else
                                                Next free ≈ <strong class="text-gray-950 dark:text-white">{{ $k['freeAt'] }}</strong> <span class="text-gray-400">(estimated)</span>
                                            @endif
                                        </span>
                                        <span class="text-gray-500 dark:text-gray-400">
                                            @if ($k['next'])
                                                Next booking {{ $k['next']['at'] }} · {{ $k['next']['registration'] }}
                                            @else
                                                No more bookings today
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>

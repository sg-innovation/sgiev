<x-filament-widgets::widget>
    @php
        $items = $this->getItems();
        $open = collect($items)->where('count', '>', 0);
        $tones = [
            'danger' => 'bg-danger-50 text-danger-600 ring-danger-200 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-400/20',
            'warning' => 'bg-warning-50 text-warning-600 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-400/20',
            'info' => 'bg-info-50 text-info-600 ring-info-200 dark:bg-info-500/10 dark:text-info-400 dark:ring-info-400/20',
            'gray' => 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
        ];
    @endphp
    <x-filament::section>
        <x-slot name="heading">
            <span class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-bell-alert" class="h-5 w-5 text-primary-500" />
                Needs your attention
            </span>
        </x-slot>
        <x-slot name="description">
            {{ $open->isEmpty() ? 'All clear. Nothing is waiting on you right now.' : $open->count().' '.\Illuminate\Support\Str::plural('item', $open->count()).' to look at' }}
        </x-slot>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($items as $item)
                @php $active = $item['count'] > 0; @endphp
                <a href="{{ $item['url'] }}"
                   class="group flex items-start gap-3 rounded-xl p-3 ring-1 transition hover:shadow-sm {{ $active ? 'bg-white ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10' : 'bg-gray-50/60 ring-gray-950/5 opacity-70 dark:bg-white/[0.02] dark:ring-white/5' }}">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1 {{ $active ? $tones[$item['tone']] : $tones['gray'] }}">
                        <x-filament::icon :icon="$item['icon']" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                            <span class="text-lg font-bold tabular-nums {{ $active ? 'text-gray-950 dark:text-white' : 'text-gray-400 dark:text-gray-500' }}">{{ number_format($item['count']) }}</span>
                        </span>
                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

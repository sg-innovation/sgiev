<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Station;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** When do customers charge? Paid bookings by slot start hour (IST), last 30 days. */
class PeakHoursChart extends ChartWidget
{
    use DashboardData;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Peak charging hours';

    protected ?string $description = 'Paid bookings by start hour (IST), last 30 days';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return \App\Support\Features::enabled('advanced_analytics');
    }

    protected function getData(): array
    {
        $from = now(Station::TIMEZONE)->startOfDay()->subDays(29)->utc();
        $rows = static::scopedBookings()
            ->whereIn('status', static::paidStatuses())
            ->where('start_at', '>=', $from)
            ->selectRaw("HOUR(CONVERT_TZ(start_at, '+00:00', '+05:30')) as h, COUNT(*) as c")
            ->groupBy('h')
            ->pluck('c', 'h');

        $hours = range(0, 23);

        return [
            'datasets' => [[
                'label' => 'Bookings',
                'data' => array_map(fn ($h) => (int) ($rows[$h] ?? 0), $hours),
                'backgroundColor' => self::SERIES_AQUA,
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 16,
            ]],
            'labels' => array_map(fn ($h) => date('ga', mktime($h, 0)), $hours),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
                y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0 } },
            },
        }
        JS);
    }
}

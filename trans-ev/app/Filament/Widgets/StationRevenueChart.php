<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Station;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Top stations by paid revenue in the last 30 days (horizontal bars, one series). */
class StationRevenueChart extends ChartWidget
{
    use DashboardData;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Top stations by revenue';

    protected ?string $description = 'Last 30 days, ₹ including GST';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return static::canSeeMoney() && \App\Support\Features::enabled('advanced_analytics');
    }

    protected function getData(): array
    {
        $from = now(Station::TIMEZONE)->startOfDay()->subDays(29)->utc();
        $rows = static::scopedBookings()
            ->whereIn('status', static::paidStatuses())
            ->where('start_at', '>=', $from)
            ->selectRaw('station_id, SUM(total_amount) as revenue')
            ->groupBy('station_id')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get();
        $names = Station::withTrashed()->whereIn('id', $rows->pluck('station_id'))->pluck('name', 'id');

        return [
            'datasets' => [[
                'label' => 'Revenue (₹)',
                'data' => $rows->pluck('revenue')->map(fn ($v) => round((float) $v, 2))->all(),
                'backgroundColor' => self::SERIES_BLUE,
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 18,
            ]],
            'labels' => $rows->map(fn ($r) => str_replace('Trans EV – ', '', (string) $names[$r->station_id]))->all(),
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
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => ' ₹' + Number(ctx.parsed.x).toLocaleString('en-IN', { maximumFractionDigits: 0 }) } },
            },
            scales: {
                x: { beginAtZero: true, border: { display: false }, ticks: { callback: (v) => '₹' + Number(v).toLocaleString('en-IN') } },
                y: { grid: { display: false } },
            },
        }
        JS);
    }
}

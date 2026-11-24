<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\DashboardData;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Daily paid revenue (₹, incl. GST). One series, one axis. */
class RevenueChart extends ChartWidget
{
    use DashboardData;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Revenue per day';

    protected ?string $description = 'Paid bookings, ₹ including GST, by slot date (IST)';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    public ?string $filter = '30';

    public static function canView(): bool
    {
        return static::canSeeMoney();
    }

    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $series = static::dailySeries(static::scopedBookings()->whereIn('status', static::paidStatuses()), $days, 'SUM(total_amount)');

        return [
            'datasets' => [[
                'label' => 'Revenue (₹)',
                'data' => array_values($series),
                'borderColor' => self::SERIES_BLUE,
                'backgroundColor' => 'rgba(42,120,214,0.12)',
                'borderWidth' => 2,
                'fill' => true,
                'tension' => 0.35,
                'pointRadius' => 0,
                'pointHoverRadius' => 5,
            ]],
            'labels' => array_values(static::days($days)),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => ' ₹' + Number(ctx.parsed.y).toLocaleString('en-IN', { maximumFractionDigits: 0 }) } },
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                y: { beginAtZero: true, border: { display: false }, ticks: { callback: (v) => '₹' + Number(v).toLocaleString('en-IN') } },
            },
        }
        JS);
    }
}

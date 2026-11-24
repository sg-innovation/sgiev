<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Widgets\Concerns\DashboardData;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Bookings per day: completed/paid vs cancelled & no-show (stacked, one axis). */
class BookingsChart extends ChartWidget
{
    use DashboardData;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Bookings per day';

    protected ?string $description = 'By slot date (IST)';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $paid = static::dailySeries(static::scopedBookings()->whereIn('status', static::paidStatuses()), $days);
        $lost = static::dailySeries(static::scopedBookings()->whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow]), $days);

        $bar = fn (string $label, array $data, string $color) => [
            'label' => $label,
            'data' => array_values($data),
            'backgroundColor' => $color,
            'borderRadius' => 4,
            'borderSkipped' => 'start',
            'borderColor' => 'transparent',
            'borderWidth' => 1,
            'maxBarThickness' => 22,
        ];

        return [
            'datasets' => [
                $bar('Paid', $paid, self::SERIES_BLUE),
                $bar('Cancelled / no-show', $lost, self::SERIES_ORANGE),
            ],
            'labels' => array_values(static::days($days)),
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
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'rectRounded' } } },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                y: { stacked: true, beginAtZero: true, border: { display: false }, ticks: { precision: 0 } },
            },
        }
        JS);
    }
}

<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Station;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** How bookings end up: paid vs cancelled vs expired holds vs no-shows (last 30 days). */
class BookingStatusChart extends ChartWidget
{
    use DashboardData;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Booking outcomes';

    protected ?string $description = 'Last 30 days';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $from = now(Station::TIMEZONE)->startOfDay()->subDays(29)->utc();
        $counts = static::scopedBookings()->where('start_at', '>=', $from)
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $get = fn (BookingStatus ...$s) => (int) collect($s)->sum(fn ($x) => $counts[$x->value] ?? 0);

        $groups = [
            'Paid / completed' => [$get(BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed), self::SERIES_BLUE],
            'Cancelled' => [$get(BookingStatus::Cancelled), self::SERIES_ORANGE],
            'Expired unpaid' => [$get(BookingStatus::Expired, BookingStatus::Held), self::SERIES_AQUA],
            'No-show' => [$get(BookingStatus::NoShow), self::NEUTRAL],
        ];

        return [
            'datasets' => [[
                'data' => array_column($groups, 0),
                'backgroundColor' => array_column($groups, 1),
                'borderWidth' => 2,
                'borderColor' => 'rgba(255,255,255,0.9)',
                'hoverOffset' => 6,
            ]],
            'labels' => collect($groups)->map(fn ($g, $label) => "{$label} ({$g[0]})")->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            cutout: '68%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle' } } },
            scales: { x: { display: false }, y: { display: false } },
        }
        JS);
    }
}

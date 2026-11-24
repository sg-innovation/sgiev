<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Enums\StationStatus;
use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Charger;
use App\Models\ContactMessage;
use App\Models\Station;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Headline KPI boxes at the top of the admin dashboard. */
class KpiOverview extends StatsOverviewWidget
{
    use DashboardData;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $bookings = static::scopedBookings();
        [$mStart, $mEnd] = static::istRange('month');
        [$pStart, $pEnd] = static::istRange('prev_month_to_date');
        [$tStart, $tEnd] = static::istRange('today');

        $paid = fn () => (clone $bookings)->whereIn('status', static::paidStatuses());

        $revMonth = (float) $paid()->whereBetween('start_at', [$mStart, $mEnd])->sum('total_amount');
        $revPrev = (float) $paid()->whereBetween('start_at', [$pStart, $pEnd])->sum('total_amount');
        $revChange = static::pctChange($revMonth, $revPrev);
        $kwhMonth = (float) $paid()->whereBetween('start_at', [$mStart, $mEnd])->sum('est_kwh');
        $countMonth = $paid()->whereBetween('start_at', [$mStart, $mEnd])->count();

        $today = (clone $bookings)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed])
            ->whereBetween('start_at', [$tStart, $tEnd])->count();
        $holds = (clone $bookings)->occupying()->where('status', BookingStatus::Held)->count();

        $stations = static::scopedStations()->get(['status']);
        $online = $stations->where('status', StationStatus::Online)->count();
        $stationIds = static::scopedStations()->pluck('id');
        $chargersOnline = Charger::query()->where('status', StationStatus::Online)->whereIn('station_id', $stationIds)->count();
        $chargersTotal = Charger::query()->whereIn('station_id', $stationIds)->count();

        $newQueries = auth()->user()?->isPlatformStaff() ? ContactMessage::query()->where('status', 'new')->count() : null;

        $revSpark = array_values(static::dailySeries($paid(), 14, 'SUM(total_amount)'));
        $bookSpark = array_values(static::dailySeries($paid(), 14));

        $stats = [];

        if (static::canSeeMoney()) {
            $stats[] = Stat::make('Revenue this month (incl. GST)', static::inr($revMonth))
                ->description($revChange === null ? 'No revenue last month to compare' : ($revChange >= 0 ? "+{$revChange}% vs last month to date" : "{$revChange}% vs last month to date"))
                ->descriptionIcon($revChange !== null && $revChange < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($revChange !== null && $revChange < 0 ? 'danger' : 'success')
                ->chart($revSpark);
        }

        $stats[] = Stat::make('Paid bookings this month', number_format($countMonth))
            ->description($today.' charging sessions today')
            ->descriptionIcon('heroicon-m-calendar-days')
            ->color('info')
            ->chart($bookSpark);

        $stats[] = Stat::make('Energy booked this month', number_format($kwhMonth, 1).' kWh')
            ->description('≈ '.number_format($kwhMonth * 7).' km of EV range')
            ->descriptionIcon('heroicon-m-bolt')
            ->color('success');

        $stats[] = Stat::make('Stations online', "{$online} / {$stations->count()}")
            ->description($stations->count() - $online > 0 ? ($stations->count() - $online).' offline or in maintenance' : 'All stations online')
            ->descriptionIcon($stations->count() - $online > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->color($stations->count() - $online > 0 ? 'warning' : 'success')
            ->url(\App\Filament\Resources\Stations\StationResource::getUrl('index'));

        $stats[] = Stat::make('Chargers available', "{$chargersOnline} / {$chargersTotal}")
            ->description($holds.' slots awaiting payment')
            ->descriptionIcon('heroicon-m-clock')
            ->color('gray');

        if ($newQueries !== null) {
            $stats[] = Stat::make('New customer queries', (string) $newQueries)
            ->description($newQueries > 0 ? 'Waiting for a reply' : 'Inbox clear')
            ->descriptionIcon($newQueries > 0 ? 'heroicon-m-inbox-arrow-down' : 'heroicon-m-check-circle')
            ->color($newQueries > 0 ? 'warning' : 'success')
            ->url(\App\Filament\Resources\ContactMessages\ContactMessageResource::getUrl('index'));
        }

        if (count($stats) % 3 !== 0) {
            // keep a 3-column grid even
            $stats[] = Stat::make('Bookings awaiting payment', (string) $holds)->color('gray');
        }

        return $stats;
    }
}

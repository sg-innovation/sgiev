<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Shared helpers for dashboard widgets: IST day buckets and role-based scoping. */
trait DashboardData
{
    /** Chart colours: validated reference palette slots (light mode) — see dataviz palette. */
    public const SERIES_BLUE = '#2a78d6';

    public const SERIES_ORANGE = '#eb6834';

    public const SERIES_AQUA = '#1baf7a';

    public const STATUS_GOOD = '#1baf7a';

    public const STATUS_WARNING = '#eda100';

    public const STATUS_CRITICAL = '#e34948';

    public const NEUTRAL = '#94a3b8';

    /** Statuses that count as revenue (paid). */
    protected static function paidStatuses(): array
    {
        return [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed];
    }

    /** Bookings visible to the current user (station managers see only their stations). */
    protected static function scopedBookings(): Builder
    {
        $q = \App\Support\Tenancy::scope(Booking::query());
        $user = auth()->user();
        if ($user && $user->hasRole(Role::StationManager->value) && ! $user->hasAnyRole(Role::companyWide())) {
            $q->whereIn('station_id', $user->stations()->pluck('stations.id'));
        }

        return $q;
    }

    /** Stations visible to the current user (company-scoped). */
    protected static function scopedStations(): Builder
    {
        return \App\Support\Tenancy::scope(Station::query()->published());
    }

    protected static function canSeeMoney(): bool
    {
        return auth()->user()?->hasAnyRole(Role::companyWide()) ?? false;
    }

    /** Last N local (IST) days as Y-m-d => label. */
    protected static function days(int $n): array
    {
        $today = now(Station::TIMEZONE)->startOfDay();

        return collect(range($n - 1, 0))->mapWithKeys(fn ($i) => [
            $today->copy()->subDays($i)->toDateString() => $today->copy()->subDays($i)->format('j M'),
        ])->all();
    }

    /**
     * Sum/count grouped by IST date of start_at.
     *
     * @return array<string, float> Y-m-d => value
     */
    protected static function dailySeries(Builder $query, int $days, string $aggregate = 'COUNT(*)'): array
    {
        $from = now(Station::TIMEZONE)->startOfDay()->subDays($days - 1)->utc();
        $rows = (clone $query)
            ->where('start_at', '>=', $from)
            ->selectRaw("DATE(CONVERT_TZ(start_at, '+00:00', '+05:30')) as d, {$aggregate} as v")
            ->groupBy('d')
            ->pluck('v', 'd');

        return collect(static::days($days))->map(fn ($_, $date) => (float) ($rows[$date] ?? 0))->all();
    }

    protected static function inr(float $v, int $decimals = 0): string
    {
        return '₹'.number_format($v, $decimals);
    }

    protected static function pctChange(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    protected static function istRange(string $period): array
    {
        $now = now(Station::TIMEZONE);

        return match ($period) {
            'today' => [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()],
            'month' => [$now->copy()->startOfMonth()->utc(), $now->copy()->endOfDay()->utc()],
            'prev_month_to_date' => [$now->copy()->subMonthNoOverflow()->startOfMonth()->utc(), $now->copy()->subMonthNoOverflow()->endOfDay()->utc()],
            default => [Carbon::parse('2000-01-01'), $now->copy()->endOfDay()->utc()],
        };
    }
}

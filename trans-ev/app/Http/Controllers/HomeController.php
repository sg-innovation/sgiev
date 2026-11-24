<?php

namespace App\Http\Controllers;

use App\Enums\ChargerType;
use App\Enums\StationStatus;
use App\Http\Resources\StationPresenter;
use App\Models\Charger;
use App\Models\Station;
use App\Models\Tariff;
use App\Support\SiteContent;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $featured = Station::published()
            ->where('status', StationStatus::Online)
            ->with(['company', 'chargers.connectorPoints', 'operatingHours', 'holidayOverrides', 'tariffs'])
            ->withMax('chargers', 'power_kw')
            ->orderByDesc('chargers_max_power_kw')
            ->limit(3)
            ->get()
            ->map(fn (Station $s) => StationPresenter::card($s));

        // Copy comes from Admin → Content → Site content; numbers come from live stations and tariffs.
        return Inertia::render('home', self::liveData() + ['featured' => $featured, 'site' => SiteContent::forPage('home')]);
    }

    /** Live network numbers and price ranges from published stations and current tariffs (cached 5 minutes). */
    public static function liveData(): array
    {
        return cache()->remember('home.data.v2', 300, function () {
            $published = fn ($q) => $q->published();

            $current = Tariff::query()
                ->whereHas('station', $published)
                ->where('effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()));

            $rates = fn (ChargerType $type) => (clone $current)->where('charger_type', $type);

            return [
                'stats' => [
                    'stations' => Station::published()->count(),
                    'online' => Station::published()->where('status', StationStatus::Online)->count(),
                    'cities' => Station::published()->distinct()->count('city'),
                    'chargers' => Charger::query()->whereHas('station', $published)->count(),
                    'maxKw' => (float) Charger::query()->whereHas('station', $published)->max('power_kw'),
                    'fromRate' => (float) (clone $current)->min('rate_per_kwh'),
                ],
                'pricing' => [
                    'acMin' => (float) $rates(ChargerType::AC)->min('rate_per_kwh'),
                    'acMax' => (float) $rates(ChargerType::AC)->max('rate_per_kwh'),
                    'dcMin' => (float) $rates(ChargerType::DC)->min('rate_per_kwh'),
                    'dcMax' => (float) $rates(ChargerType::DC)->max('rate_per_kwh'),
                    'sessionFee' => (float) ((clone $current)->min('session_fee') ?? 0),
                    'gstRate' => (float) ((clone $current)->max('gst_rate') ?? 18),
                    'idleFee' => (float) ((clone $current)->max('idle_fee_per_min') ?? 0),
                    'idleGrace' => (int) ((clone $current)->min('idle_grace_minutes') ?? 0),
                ],
                'cities' => Station::published()
                    ->selectRaw('city, state, COUNT(*) as stations, SUM(status = ?) as online', [StationStatus::Online->value])
                    ->groupBy('city', 'state')
                    ->orderByDesc('stations')->orderBy('city')
                    ->get()
                    ->map(fn ($r) => ['city' => $r->city, 'state' => $r->state, 'stations' => (int) $r->stations, 'online' => (int) $r->online]),
            ];
        });
    }
}

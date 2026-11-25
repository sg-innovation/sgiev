<?php

namespace App\Http\Controllers;

use App\Enums\ChargerType;
use App\Enums\ConnectorType;
use App\Http\Resources\StationPresenter;
use App\Models\RuleSet;
use App\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\PublicHtml;

class StationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('stations/index', [
            'filters' => [
                'types' => collect(ChargerType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->getLabel()]),
                'connectors' => collect(ConnectorType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->getLabel()]),
                'cities' => Station::published()->distinct()->orderBy('city')->pluck('city'),
            ],
            'mapStyle' => config('services.maps.style'),
        ]);
    }

    /** JSON search used by the map and list views. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['nullable', 'numeric', 'between:1,3000'],
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ChargerType::class)],
            'connector' => ['nullable', Rule::enum(ConnectorType::class)],
            'min_kw' => ['nullable', 'numeric', 'between:0,500'],
            'open_now' => ['nullable', 'boolean'],
            'available' => ['nullable', 'boolean'],
        ]);

        $query = Station::published()
            ->with(['company', 'chargers.connectorPoints', 'operatingHours', 'holidayOverrides', 'tariffs'])
            ->when(filled($data['q'] ?? null), function (Builder $q) use ($data) {
                $term = '%'.addcslashes($data['q'], '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('city', 'like', $term)
                    ->orWhere('address_line1', 'like', $term)->orWhere('pincode', 'like', $term));
            })
            ->when($data['type'] ?? null, fn (Builder $q, $type) => $q->whereHas('chargers', fn ($c) => $c->where('type', $type)))
            ->when($data['connector'] ?? null, fn (Builder $q, $conn) => $q->whereHas('chargers', fn ($c) => $c->whereJsonContains('connectors', $conn)))
            ->when($data['min_kw'] ?? null, fn (Builder $q, $kw) => $q->whereHas('chargers', fn ($c) => $c->where('power_kw', '>=', $kw)));

        if (isset($data['lat'])) {
            $query->nearest((float) $data['lat'], (float) $data['lng'], isset($data['radius']) ? (float) $data['radius'] : null);
        } else {
            $query->orderBy('city')->orderBy('name');
        }

        $stations = $query->limit(100)->get()->map(fn (Station $s) => StationPresenter::card($s));

        if ($request->boolean('open_now')) {
            $stations = $stations->where('isOpen', true);
        }
        if ($request->boolean('available')) {
            $stations = $stations->where('bookable', true);
        }

        return response()->json(['data' => $stations->values()]);
    }

    public function show(Station $station): Response
    {
        $station->load(['company', 'chargers.connectorPoints', 'operatingHours', 'holidayOverrides', 'tariffs.charger']);
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $rules = RuleSet::activeFor($station);

        return Inertia::render('stations/show', [
            'station' => StationPresenter::detail($station),
            'cancellationPolicy' => ($cp = \App\Models\CancellationPolicy::activeFor($station)) ? \App\Http\Resources\BookingPresenter::policy($cp->snapshot()) : null,
            'rules' => $rules ? ['title' => $rules->title, 'version' => $rules->version, 'content' => PublicHtml::clean($rules->content)] : null,
            'mapStyle' => config('services.maps.style'),
            'reviews' => (function () use ($station) {
                $svc = app(\App\Services\ReviewService::class);

                return ['avg' => $station->rating_count ? round((float) $station->rating_avg, 1) : null, 'count' => (int) $station->rating_count,
                    'distribution' => $svc->distribution($station), 'items' => $svc->forStation($station)];
            })(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BookingController as WebBookingController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StationController as WebStationController;
use App\Http\Resources\BookingPresenter;
use App\Http\Resources\StationPresenter;
use App\Models\CancellationPolicy;
use App\Models\Lookup;
use App\Models\RuleSet;
use App\Models\Station;
use App\Services\AvailabilityService;
use App\Services\ReviewService;
use App\Support\BusinessRules;
use App\Support\PublicHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public catalogue: app configuration, station search (same filters as the website), station details with prices,
 * rules, cancellation policy and reviews, free slots and price estimates. The slot and estimate endpoints are the
 * website's own, so the app and the site always agree.
 */
class CatalogController extends Controller
{
    public function config(AvailabilityService $availability): JsonResponse
    {
        $brand = \App\Models\Company::primary();

        return response()->json([
            'app' => ['name' => config('app.name'), 'api_version' => 'v1', 'timezone' => Station::TIMEZONE, 'currency' => 'INR'],
            'support' => ['email' => $brand?->support_email ?: $brand?->email, 'phone' => $brand?->support_phone ?: $brand?->phone],
            'booking' => [
                'bookable_dates' => $availability->bookableDates(),
                'hold_minutes' => BusinessRules::int('booking.hold_minutes'),
                'check_in_opens_minutes_before' => BusinessRules::int('checkin.early_minutes'),
            ],
            'connector_types' => collect(\App\Enums\ConnectorType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->getLabel()])->values(),
            'ev_models' => Lookup::options('ev_model'),
            'review_tags' => Lookup::options('review_tag'),
            'legal' => ['terms' => url('/terms'), 'privacy' => url('/privacy'), 'refund_policy' => url('/refund-policy')],
        ]);
    }

    /** GET /stations – lat/lng/radius, q, type (AC/DC), connector, min_kw, open_now, available. */
    public function stations(Request $request): JsonResponse
    {
        return app(WebStationController::class)->search($request);
    }

    public function station(Station $station): JsonResponse
    {
        $station->load(['company', 'chargers.connectorPoints', 'operatingHours', 'holidayOverrides', 'tariffs.charger']);
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $rules = RuleSet::activeFor($station);
        $reviews = app(ReviewService::class);

        return response()->json(['data' => StationPresenter::detail($station) + [
            'cancellation_policy' => ($cp = CancellationPolicy::activeFor($station)) ? BookingPresenter::policy($cp->snapshot()) : null,
            'rules' => $rules ? ['id' => $rules->id, 'title' => $rules->title, 'version' => $rules->version, 'content_html' => PublicHtml::clean($rules->content)] : null,
            'rating' => ['average' => $station->rating_count ? round((float) $station->rating_avg, 1) : null, 'count' => (int) $station->rating_count, 'distribution' => $reviews->distribution($station)],
        ]]);
    }

    public function reviews(Station $station): JsonResponse
    {
        return response()->json(['data' => app(ReviewService::class)->forStation($station)]);
    }

    /** GET /stations/{slug}/availability – free slots (same as the website). */
    public function availability(Request $request, Station $station): JsonResponse
    {
        return app(WebBookingController::class)->availability($request, $station);
    }

    /** POST /stations/{slug}/estimate – price before booking (same as the website). */
    public function estimate(Request $request, Station $station): JsonResponse
    {
        return app(WebBookingController::class)->estimate($request, $station);
    }
}

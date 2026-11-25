<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\ConnectorType;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingPresenter;
use App\Http\Resources\StationPresenter;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\RuleSet;
use App\Models\Station;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\PricingService;
use App\Support\IndianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\PublicHtml;

class BookingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly BookingService $bookings,
    ) {}

    /** Booking wizard. */
    public function create(Request $request, Station $station): Response|RedirectResponse
    {
        $station->load(['company', 'chargers.connectorPoints', 'operatingHours', 'holidayOverrides', 'tariffs.charger']);
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $rules = RuleSet::activeFor($station);
        abort_if($rules === null, 503, 'Station rules are not configured.');

        $user = $request->user();
        $last = $user->bookings()->latest()->first();

        return Inertia::render('booking/create', [
            'station' => StationPresenter::detail($station),
            'offers' => app(\App\Services\CouponService::class)->offersFor($station)->map(fn ($c) => \App\Services\CouponService::present($c))->values(),
            'chargers' => $station->chargers->map(fn (Charger $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'type' => $c->type->value,
                'powerKw' => (float) $c->power_kw,
                'connectors' => collect($c->connectors)->map(fn ($v) => ['value' => $v, 'label' => ConnectorType::tryFrom($v)?->getLabel() ?? $v])->values(),
                'online' => $c->status->isBookable(),
                'units' => $c->connectorPoints->map(fn (\App\Models\Connector $k) => [
                    'id' => $k->id,
                    'no' => $k->connector_no,
                    'type' => $k->type->value,
                    'typeLabel' => $k->type->getLabel(),
                    'powerKw' => (float) ($k->max_kw ?? $c->power_kw),
                    'bookable' => $k->setRelation('charger', $c)->isBookable(),
                    'liveStatus' => $k->live_status->value,
                    'liveLabel' => $k->live_status->getLabel(),
                ])->values(),
            ])->values(),
            'dates' => collect($this->availability->bookableDates())->map(fn ($d) => [
                'value' => $d,
                'day' => Carbon::parse($d)->format('D'),
                'date' => Carbon::parse($d)->format('j M'),
            ]),
            'durations' => $this->availability->durations($station),
            'rules' => [
                'id' => $rules->id,
                'version' => $rules->version,
                'title' => $rules->title,
                'content' => PublicHtml::clean($rules->content),
            ],
            'connectors' => collect(ConnectorType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->getLabel()]),
            'cancellationPolicy' => ($cp = \App\Models\CancellationPolicy::activeFor($station)) ? BookingPresenter::policy($cp->snapshot()) : null,
            'evModels' => \App\Models\Lookup::list('ev_model')->map(fn ($l) => [
                'make' => $l->meta['make'] ?? '', 'model' => $l->meta['model'] ?? $l->label, 'battery' => (float) ($l->meta['battery_kwh'] ?? 0), 'connector' => $l->meta['connector'] ?? 'ccs2',
            ])->values(),
            'states' => collect(IndianStates::STATES)->map(fn ($name, $code) => ['value' => (string) $code, 'label' => $name])->values(),
            'vehicles' => $user->vehicles()->reorder()->orderByDesc('is_default')->latest()->get()->map(fn ($v) => [
                'id' => $v->id,
                'make' => $v->make,
                'model' => $v->model,
                'registration_no' => $v->registration_no,
                'battery_kwh' => (float) $v->battery_kwh,
                'connector' => $v->connector->value,
            ]),
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'gstin' => $last?->customer_snapshot['gstin'] ?? $user->gstin ?? '',
                'state_code' => $last?->customer_snapshot['state_code'] ?? $user->state_code ?? $station->state_code,
            ],
            'holdMinutes' => \App\Support\BusinessRules::int('booking.hold_minutes'),
        ]);
    }

    public function availability(Request $request, Station $station): JsonResponse
    {
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::in($this->availability->bookableDates())],
            'duration' => ['required', 'integer', Rule::in($this->availability->durations($station))],
        ]);

        return response()->json([
            'chargers' => $this->availability->grid($station, $data['date'], (int) $data['duration']),
        ]);
    }

    public function estimate(Request $request, Station $station): JsonResponse
    {
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $data = $request->validate([
            'charger_id' => ['required', 'integer'],
            'battery_kwh' => ['required', 'decimal:0,2', 'between:1,200'],
            'battery_from' => ['required', 'integer', 'between:0,99'],
            'battery_to' => ['required', 'integer', 'between:1,100', 'gt:battery_from'],
            'duration' => ['required', 'integer', Rule::in($this->availability->durations($station))],
            'coupon_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'start' => ['nullable', 'date'],
        ]);
        $charger = $station->chargers()->findOrFail($data['charger_id']);
        $start = filled($data['start'] ?? null) ? Carbon::parse($data['start'])->utc() : null;
        $args = [$station, $charger, (string) $data['battery_kwh'], (int) $data['battery_from'], (int) $data['battery_to'], (int) $data['duration'], $start];
        $estimate = $this->pricing->estimate(...$args);

        // An invalid coupon never blocks the estimate: we return the full price plus a message.
        $couponError = null;
        if (filled($data['coupon_code'] ?? null)) {
            try {
                $coupon = app(\App\Services\CouponService::class)->resolve($data['coupon_code'], $station, $charger, $request->user(), $estimate['subtotal_amount']);
                $estimate = $this->pricing->estimate(...[...$args, $coupon]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $couponError = $e->errors()['coupon_code'][0] ?? 'This coupon code is not valid.';
            }
        }

        return response()->json(PricingService::present($estimate) + ['coupon_error' => $couponError]);
    }

    public function store(StoreBookingRequest $request, Station $station): RedirectResponse
    {
        $data = $request->validated();
        $charger = $station->chargers()->findOrFail($data['charger_id']);
        $connector = filled($data['connector_id'] ?? null) ? $charger->connectorPoints()->findOrFail($data['connector_id']) : null;
        $rules = RuleSet::query()->findOrFail($data['rule_set_id']);

        $booking = $this->bookings->hold(
            user: $request->user(),
            station: $station,
            charger: $charger,
            start: Carbon::parse($data['start'])->utc(),
            durationMinutes: (int) $data['duration'],
            batteryFrom: (int) $data['battery_from'],
            batteryTo: (int) $data['battery_to'],
            customer: collect($data)->only(['name', 'email', 'phone', 'gstin', 'state_code'])->all(),
            vehicle: [
                'make' => $data['make'],
                'model' => $data['model'],
                'registration_no' => $data['registration_no'],
                'battery_kwh' => (string) $data['battery_kwh'],
                'connector' => $data['connector'],
                'save' => (bool) ($data['save_vehicle'] ?? false),
            ],
            acceptedRules: $rules,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            connector: $connector,
            couponCode: $data['coupon_code'] ?? null,
        );

        if (blank($request->user()->phone)) {
            $request->user()->forceFill(['phone' => $data['phone']])->save();
        }

        return to_route('bookings.show', $booking)->with('success', 'Slot reserved. Complete payment to confirm your booking.');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), ['upcoming', 'past', 'cancelled', 'all'], true) ? $request->query('tab') : 'upcoming';
        $search = trim((string) $request->query('q', ''));
        $paid = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow];
        $cancelled = [BookingStatus::Cancelled, BookingStatus::Expired];
        $base = fn () => $user->bookings();

        $query = $base()->with(['station', 'charger', 'connectorPoint', 'invoice', 'payments'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhereHas('station', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('city', 'like', "%{$search}%"))));
        match ($tab) {
            'upcoming' => $query->where('end_at', '>', now())->where(fn ($q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
                ->orWhere(fn ($h) => $h->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now())))->orderBy('start_at'),
            'past' => $query->whereIn('status', $paid)->where('end_at', '<=', now())->latest('start_at'),
            'cancelled' => $query->whereIn('status', $cancelled)->latest('start_at'),
            default => $query->latest('start_at'),
        };

        return Inertia::render('booking/index', [
            'tab' => $tab,
            'search' => $search,
            'counts' => [
                'upcoming' => $base()->where('end_at', '>', now())->where(fn ($q) => $q->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
                    ->orWhere(fn ($h) => $h->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now())))->count(),
                'past' => $base()->whereIn('status', $paid)->where('end_at', '<=', now())->count(),
                'cancelled' => $base()->whereIn('status', $cancelled)->count(),
                'all' => $base()->count(),
            ],
            'stats' => [
                'sessions' => $base()->whereIn('status', $paid)->count(),
                'kwh' => (float) $base()->whereIn('status', $paid)->sum('est_kwh'),
                'spent' => (float) $base()->whereIn('status', $paid)->sum('total_amount'),
                'saved' => (float) $base()->whereIn('status', $paid)->sum('discount_amount'),
            ],
            'bookings' => $query->paginate(10)->withQueryString()->through(fn (Booking $b) => BookingPresenter::summary($b) + [
                'connector' => $b->connectorPoint ? '#'.$b->connectorPoint->connector_no.' '.$b->connectorPoint->type->getLabel() : $b->connector,
                'lat' => (float) $b->station->latitude,
                'lng' => (float) $b->station->longitude,
                'kwh' => (float) $b->est_kwh,
                'invoiceUrl' => $b->invoice ? route('bookings.invoice', $b) : null,
                'receiptUrl' => $b->payments->contains('status', \App\Models\Payment::SUCCESS) ? route('bookings.receipt', $b) : null,
                'coupon' => $b->coupon_code,
            ]),
        ]);
    }

    public function show(Request $request, Booking $booking): Response
    {
        Gate::authorize('view', $booking);
        $booking->load(['station', 'charger', 'ruleAcceptance']);

        return Inertia::render('booking/show', ['booking' => BookingPresenter::detail($booking)]);
    }

    /** D3: rate the station and charger after a completed booking. */
    public function review(Request $request, Booking $booking): RedirectResponse
    {
        $d = $request->validate([
            'station_rating' => ['required', 'integer', 'between:1,5'], 'charger_rating' => ['nullable', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:6'], 'tags.*' => ['string', 'max:40'], 'body' => ['nullable', 'string', 'max:1000'],
        ]);
        $r = app(\App\Services\ReviewService::class)->submit($booking, $request->user(), $d);

        return to_route('bookings.show', $booking)->with('success', $r->status === 'pending'
            ? 'Thanks for your review! It will appear on the station page once it has been checked.'
            : 'Thanks for rating your charge!');
    }

    /** C9: live charging data for the customer's tracking card (polled). */
    public function live(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403);

        return response()->json(app(\App\Services\LiveSessionService::class)->forBooking($booking))->header('Cache-Control', 'no-store');
    }

    public function requestStop(Request $request, Booking $booking): RedirectResponse
    {
        app(\App\Services\LiveSessionService::class)->requestStop($booking, $request->user());

        return to_route('bookings.show', $booking)->with('success', 'We’ve asked the station staff to stop charging. Please stay near your vehicle.');
    }

    /** C6: free slots for moving this booking on a day. */
    public function rescheduleOptions(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403);
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d', Rule::in($this->availability->bookableDates())]])['date'];
        $svc = app(\App\Services\RescheduleService::class);

        return response()->json(['blocker' => $svc->customerBlocker($booking, $request->user()), 'connectors' => $svc->options($booking->load('station'), $date)]);
    }

    public function reschedule(Request $request, Booking $booking): RedirectResponse
    {
        $d = $request->validate(['connector_id' => ['required', 'integer'], 'start' => ['required', 'date']]);
        $connector = \App\Models\Connector::query()->findOrFail($d['connector_id']);
        $b = app(\App\Services\RescheduleService::class)->reschedule($booking, $connector, \Illuminate\Support\Carbon::parse($d['start']), $request->user(), false);

        return to_route('bookings.show', $b)->with('success', 'Booking moved to '.$b->start_at->timezone(\App\Models\Station::TIMEZONE)->format('D, j M, g:i A').' on charger '.$b->charger->code.'.');
    }

    /** C5: the customer tells the station they are running late. */
    public function runningLate(Request $request, Booking $booking): RedirectResponse
    {
        $d = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:240'], 'note' => ['nullable', 'string', 'max:160']]);
        $message = app(\App\Services\NoShowService::class)->runningLate($booking, $request->user(), (int) $d['minutes'], $d['note'] ?? null);

        return to_route('bookings.show', $booking)->with('success', $message);
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);
        $refund = app(\App\Services\Payments\RefundService::class)->cancel($booking, 'Cancelled by customer', $request->user(), false);

        return to_route('bookings.show', $booking)->with('success', $refund
            ? 'Booking cancelled. A refund of ₹'.number_format((float) $refund->amount, 2).' has been started to your original payment method.'
            : 'Booking cancelled and the slot released.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\BookingController as WebBookingController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingPresenter;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RuleSet;
use App\Models\Station;
use App\Services\BookingService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * The customer's bookings: reserve a slot (held until paid), pay (hosted checkout URL), cancel with the policy refund,
 * reschedule, running late, check-in pass (QR), live charging, stop request, receipt, GST invoice and rating.
 * The booking object is the same one the website shows (BookingPresenter::detail).
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /** GET /bookings?tab=upcoming|past|cancelled|all&page= */
    public function index(Request $request): JsonResponse
    {
        $tab = in_array($request->query('tab'), ['upcoming', 'past', 'cancelled', 'all'], true) ? $request->query('tab') : 'upcoming';
        $paid = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow];
        $q = $request->user()->bookings()->with(['station', 'charger', 'connectorPoint', 'invoice', 'payments']);
        match ($tab) {
            'upcoming' => $q->where('end_at', '>', now())->where(fn ($w) => $w->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn])
                ->orWhere(fn ($h) => $h->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now())))->orderBy('start_at'),
            'past' => $q->whereIn('status', $paid)->where('end_at', '<=', now())->latest('start_at'),
            'cancelled' => $q->whereIn('status', [BookingStatus::Cancelled, BookingStatus::Expired])->latest('start_at'),
            default => $q->latest('start_at'),
        };
        $page = $q->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Booking $b) => BookingPresenter::summary($b) + [
                'station_slug' => $b->station->slug, 'latitude' => (float) $b->station->latitude, 'longitude' => (float) $b->station->longitude,
                'kwh' => (float) $b->est_kwh, 'has_invoice' => (bool) $b->invoice,
            ])->values(),
            'meta' => ['tab' => $tab, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** POST /stations/{slug}/bookings – hold a slot. Pay within the hold time to confirm it. */
    public function store(StoreBookingRequest $request, Station $station): JsonResponse
    {
        $d = $request->validated();
        $charger = $station->chargers()->findOrFail($d['charger_id']);
        $connector = filled($d['connector_id'] ?? null) ? $charger->connectorPoints()->findOrFail($d['connector_id']) : null;
        $booking = $this->bookings->hold(
            user: $request->user(), station: $station, charger: $charger, start: Carbon::parse($d['start'])->utc(), durationMinutes: (int) $d['duration'],
            batteryFrom: (int) $d['battery_from'], batteryTo: (int) $d['battery_to'],
            customer: collect($d)->only(['name', 'email', 'phone', 'gstin', 'state_code'])->all(),
            vehicle: ['make' => $d['make'], 'model' => $d['model'], 'registration_no' => $d['registration_no'], 'battery_kwh' => (string) $d['battery_kwh'],
                'connector' => $d['connector'], 'save' => (bool) ($d['save_vehicle'] ?? false)],
            acceptedRules: RuleSet::query()->findOrFail($d['rule_set_id']), ip: $request->ip(), userAgent: $request->userAgent() ?? 'app',
            connector: $connector, couponCode: $d['coupon_code'] ?? null,
        );
        if (blank($request->user()->phone)) {
            $request->user()->forceFill(['phone' => $d['phone']])->save();
        }

        return response()->json(['data' => $this->detail($booking), 'message' => 'Slot reserved. Pay to confirm.'], 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return response()->json(['data' => $this->detail($booking)]);
    }

    /** POST /bookings/{code}/pay – returns the hosted checkout URL; open it in a browser / web view. */
    public function pay(Request $request, Booking $booking, PaymentService $payments): JsonResponse
    {
        Gate::authorize('pay', $booking);

        return response()->json(['url' => $payments->start($booking), 'message' => 'Open this URL to pay. Then call payment-status or reload the booking.']);
    }

    /** POST /bookings/{code}/payment-status – ask the gateway again (after returning from checkout). */
    public function paymentStatus(Request $request, Booking $booking, PaymentService $payments): JsonResponse
    {
        Gate::authorize('view', $booking);
        $booking->payments()->where('status', Payment::PENDING)->get()->each(fn (Payment $p) => $payments->reconcile($p));

        return response()->json(['data' => $this->detail($booking->fresh())]);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('cancel', $booking);
        $refund = app(\App\Services\Payments\RefundService::class)->cancel($booking, 'Cancelled by customer', $request->user(), false);

        return response()->json(['data' => $this->detail($booking->fresh()), 'refund' => $refund ? ['amount' => (float) $refund->amount, 'status' => $refund->status] : null]);
    }

    public function rescheduleOptions(Request $request, Booking $booking): JsonResponse
    {
        return app(WebBookingController::class)->rescheduleOptions($request, $booking);
    }

    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 404);
        $d = $request->validate(['connector_id' => ['required', 'integer'], 'start' => ['required', 'date']]);
        $b = app(\App\Services\RescheduleService::class)->reschedule($booking, \App\Models\Connector::query()->findOrFail($d['connector_id']), Carbon::parse($d['start']), $request->user(), false);

        return response()->json(['data' => $this->detail($b)]);
    }

    public function runningLate(Request $request, Booking $booking): JsonResponse
    {
        $d = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:240'], 'note' => ['nullable', 'string', 'max:160']]);
        $message = app(\App\Services\NoShowService::class)->runningLate($booking, $request->user(), (int) $d['minutes'], $d['note'] ?? null);

        return response()->json(['message' => $message, 'data' => $this->detail($booking->fresh())]);
    }

    /** GET /bookings/{code}/pass – the rotating check-in QR (token + URL + expiry). */
    public function pass(Request $request, Booking $booking): JsonResponse
    {
        return app(CheckInController::class)->pass($request, $booking);
    }

    /** GET /bookings/{code}/live – charging progress (poll every few seconds while charging). */
    public function live(Request $request, Booking $booking): JsonResponse
    {
        return app(WebBookingController::class)->live($request, $booking);
    }

    public function stop(Request $request, Booking $booking): JsonResponse
    {
        app(\App\Services\LiveSessionService::class)->requestStop($booking, $request->user());

        return response()->json(['message' => 'Stop requested.', 'data' => app(\App\Services\LiveSessionService::class)->forBooking($booking->fresh())]);
    }

    public function review(Request $request, Booking $booking): JsonResponse
    {
        $d = $request->validate([
            'station_rating' => ['required', 'integer', 'between:1,5'], 'charger_rating' => ['nullable', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:6'], 'tags.*' => ['string', 'max:40'], 'body' => ['nullable', 'string', 'max:1000'],
        ]);
        $r = app(\App\Services\ReviewService::class)->submit($booking, $request->user(), $d);

        return response()->json(['status' => $r->status, 'message' => $r->status === 'pending' ? 'Thanks! Your review will appear once checked.' : 'Thanks for rating your charge!'], 201);
    }

    /** GET /bookings/{code}/documents – receipt and GST documents as short-lived download links. */
    public function documents(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);
        $booking->loadMissing(['invoice', 'creditNotes']);
        $link = fn (string $route, array $params) => URL::temporarySignedRoute($route, now()->addMinutes(30), $params);

        return response()->json(['data' => [
            'invoice' => $booking->invoice ? ['number' => $booking->invoice->number, 'total' => (float) $booking->invoice->total, 'pdf_url' => $link('api.v1.documents.invoice', ['booking' => $booking->code])] : null,
            'credit_notes' => $booking->creditNotes->map(fn ($c) => ['number' => $c->number, 'type' => $c->note_type ?? 'credit', 'total' => (float) $c->total,
                'pdf_url' => $link('api.v1.documents.credit-note', ['creditNote' => $c->uuid])])->values(),
        ]]);
    }

    private function detail(Booking $b): array
    {
        $b->loadMissing(['station', 'charger', 'ruleAcceptance']);

        return BookingPresenter::detail($b);
    }
}

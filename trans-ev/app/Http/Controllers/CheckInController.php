<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Station;
use App\Services\CheckInService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** C1: customer check-in pass, staff scan → confirm, customer charger-sticker scan, printable charger QR. */
class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $service) {}

    /** JSON for the customer's self-refreshing QR pass. */
    public function pass(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403);
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true) || $booking->end_at->isPast()) {
            return response()->json(['available' => false]);
        }
        if ($booking->status === BookingStatus::CheckedIn) {
            return response()->json(['available' => true, 'checkedIn' => true, 'checkedInAt' => $booking->checked_in_at?->timezone(Station::TIMEZONE)->format('g:i A')]);
        }
        $p = $this->service->passUrl($booking);

        return response()->json([
            'available' => true, 'checkedIn' => false, 'qr' => CheckInService::qrSvg($p['url']),
            'expiresAt' => $p['expires_at']->toIso8601String(), 'blocker' => $this->service->blocker($booking),
        ])->header('Cache-Control', 'no-store');
    }

    /** A scanned booking QR. Staff of that station get the confirm page; the customer is sent to their booking. */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        try {
            $booking = $this->service->verify($token);
        } catch (ValidationException $e) {
            return Inertia::render('checkin/confirm', ['error' => collect($e->errors())->flatten()->first(), 'booking' => null, 'token' => $token]);
        }
        if ($booking->user_id === $request->user()->id && ! $request->user()->can('checkIn', $booking)) {
            return to_route('bookings.show', $booking)->with('success', 'Show this QR to the station staff to check in.');
        }
        Gate::authorize('checkIn', $booking);

        return Inertia::render('checkin/confirm', ['error' => null, 'token' => $token, 'booking' => $this->present($booking)]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $booking = $this->service->verify($token);
        Gate::authorize('checkIn', $booking);
        $this->service->checkIn($booking, 'staff_scan', $request->user());

        return to_route('checkin.show', $token)->with('success', "{$booking->code} checked in.");
    }

    /** Customer scanned the sticker on a charger. */
    public function charger(Request $request, string $key): Response
    {
        $charger = Charger::query()->where('qr_key', $key)->with(['station', 'connectorPoints'])->firstOrFail();
        abort_unless($charger->station && Station::query()->published()->whereKey($charger->station_id)->exists(), 404);

        return Inertia::render('checkin/charger', [
            'charger' => [
                'key' => $key, 'code' => $charger->code, 'name' => $charger->name, 'type' => $charger->type->value, 'powerKw' => (float) $charger->power_kw,
                'station' => ['name' => $charger->station->name, 'slug' => $charger->station->slug, 'city' => $charger->station->city],
            ],
            'bookings' => $this->service->bookingsFor($request->user(), $charger)->map(fn (Booking $b) => $this->present($b) + [
                'thisCharger' => $b->charger_id === $charger->id,
            ])->values(),
        ]);
    }

    public function chargerCheckIn(Request $request, string $key): RedirectResponse
    {
        $charger = Charger::query()->where('qr_key', $key)->firstOrFail();
        $booking = Booking::query()->where('code', (string) $request->input('booking'))->where('user_id', $request->user()->id)->firstOrFail();
        $this->service->checkIn($booking, 'charger_scan', $request->user(), $charger);

        return to_route('bookings.show', $booking)->with('success', 'You’re checked in. Plug in and start charging.');
    }

    /** Printable QR sticker for a charger (staff). */
    public function sticker(Request $request, Charger $charger): \Illuminate\Contracts\View\View
    {
        $charger->load('station.company', 'connectorPoints');
        abort_unless($request->user()->can('stations.view') && Tenancy::owns($charger->station->company_id), 403);

        return view('checkin.sticker', [
            'charger' => $charger, 'url' => $this->service->chargerUrl($charger), 'qr' => CheckInService::qrSvg($this->service->chargerUrl($charger)),
        ]);
    }

    private function present(Booking $b): array
    {
        $b->loadMissing(['station', 'charger', 'connectorPoint']);
        $tz = Station::TIMEZONE;

        return [
            'code' => $b->code, 'status' => $b->status->value, 'statusLabel' => $b->status->getLabel(),
            'customer' => $b->customer_snapshot['name'] ?? null, 'phone' => $b->customer_snapshot['phone'] ?? null,
            'vehicle' => trim(($b->vehicle_snapshot['make'] ?? '').' '.($b->vehicle_snapshot['model'] ?? '')), 'registration' => $b->vehicle_snapshot['registration_no'] ?? null,
            'station' => $b->station->name, 'charger' => $b->charger?->code.' · '.$b->charger?->type?->value.' '.(float) $b->charger?->power_kw.' kW',
            'connector' => $b->connectorPoint?->label(),
            'date' => $b->start_at->timezone($tz)->format('D, j M'), 'time' => $b->start_at->timezone($tz)->format('g:i A').' – '.$b->end_at->timezone($tz)->format('g:i A'),
            'checkedInAt' => $b->checked_in_at?->timezone($tz)->format('g:i A'),
            'blocker' => $this->service->blocker($b),
            'adminUrl' => $b->user_id !== auth()->id() ? \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $b], panel: 'admin') : null,
        ];
    }
}

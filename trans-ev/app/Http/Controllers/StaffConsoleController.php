<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\ConnectorLiveStatus;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Services\ChargingSessionService;
use App\Services\CheckInService;
use App\Services\ConnectorStatusService;
use App\Services\Payments\RefundService;
use App\Support\BusinessRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * C2 Staff Console (/staff): mobile-first screen for station staff. Today's arrivals with check-in, start/stop
 * charging (manual sessions), no-show, and connector status. Every action is permission- and station-scoped.
 */
class StaffConsoleController extends Controller
{
    /** Live statuses staff may set by hand (charging/preparing come from sessions). */
    public const MANUAL_STATUSES = ['available', 'occupied', 'faulted', 'unavailable'];

    public function __construct(private readonly ChargingSessionService $sessions, private readonly CheckInService $checkIns) {}

    public static function canUse(?User $user): bool
    {
        return $user !== null && $user->isStaff() && ($user->can('bookings.manage') || $user->can('sessions.manage') || $user->can('stations.status') || $user->can('walkins.manage'));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(self::canUse($user), 403);
        $stations = ChargingSessionService::stations($user)->orderBy('name')->get(['id', 'name', 'city', 'code']);
        $station = $stations->firstWhere('id', (int) $request->query('station')) ?? $stations->first();

        return Inertia::render('staff/console', [
            'stations' => $stations->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name, 'city' => $s->city])->values(),
            'station' => $station ? ['id' => $station->id, 'name' => $station->name] : null,
            'today' => now(Station::TIMEZONE)->format('l, j M'),
            'arrivals' => $station ? $this->arrivals($station, $user) : [],
            'chargers' => $station ? $this->chargers($station) : [],
            'can' => ['checkIn' => $user->can('bookings.manage'), 'sessions' => $user->can('sessions.manage'), 'status' => $user->can('stations.status'),
                'walkIns' => $user->can('walkins.manage') && $user->can('sessions.manage')],
            'walkIn' => $station && $user->can('walkins.manage') ? $this->walkInOptions($station) : null,
            'stopReasons' => ChargingSession::STOP_REASONS,
            'manualStatuses' => collect(self::MANUAL_STATUSES)->mapWithKeys(fn ($s) => [$s => ConnectorLiveStatus::from($s)->getLabel()]),
            'noShowMinutes' => BusinessRules::int('booking.no_show_minutes'),
        ]);
    }

    public function walkIn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'connector_id' => ['required', 'integer'], 'registration_no' => ['required', 'string', 'max:15', 'regex:/^[A-Za-z0-9 \-]{4,15}$/'],
            'make' => ['nullable', 'string', 'max:40'], 'model' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'regex:/^[6-9][0-9]{9}$/'], 'email' => ['nullable', 'email', 'max:120'],
            'gstin' => ['nullable', 'string', 'max:15'], 'minutes' => ['required', 'integer', 'min:10', 'max:720'],
            'meter_start' => ['nullable', 'numeric', 'min:0', 'max:99999999'], 'informed' => ['accepted'],
        ], [
            'phone.regex' => 'Enter a 10-digit Indian mobile number.', 'informed.accepted' => 'Tell the driver the price and station rules first.',
            'registration_no.regex' => 'Enter the number plate, e.g. MH12AB1234.',
        ]);
        $connector = Connector::query()->with('charger.station')->findOrFail($data['connector_id']);
        $booking = app(\App\Services\WalkInService::class)->start($connector, $data, $request->user());

        return back()->with('success', "Walk-in {$booking->code} started on {$connector->label()}.");
    }

    public function collect(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', 'string'], 'reference' => ['nullable', 'string', 'max:30']]);
        $due = \App\Services\WalkInService::amountDue($booking);
        app(\App\Services\WalkInService::class)->collect($booking, $data['method'], $data['reference'] ?? null, $request->user());

        return back()->with('success', '₹'.number_format((float) $due, 2)." received for {$booking->code}. ".($booking->source === 'walk_in' ? 'GST invoice issued.' : 'GST debit note issued.'));
    }

    public function paymentLink(Request $request, Booking $booking): \Illuminate\Http\JsonResponse
    {
        $url = app(\App\Services\WalkInService::class)->paymentLink($booking, $request->user());

        return response()->json(['url' => $url, 'qr' => CheckInService::qrSvg($url)])->header('Cache-Control', 'no-store');
    }

    /** Free connectors and how long a walk-in may use each before the next online booking. */
    private function walkInOptions(Station $station): array
    {
        $svc = app(\App\Services\WalkInService::class);
        $tz = Station::TIMEZONE;
        $busy = ChargingSession::query()->where('station_id', $station->id)->where('status', 'active')->pluck('connector_id')->all();
        $connectors = Connector::query()->whereHas('charger', fn ($q) => $q->where('station_id', $station->id))->with('charger.station')->get()
            ->filter(fn (Connector $c) => $c->status?->value === 'online' && ! $c->live_status?->isDown() && ! $c->live_status?->isBusy() && ! in_array($c->id, $busy, true))
            ->map(fn (Connector $c) => [
                'id' => $c->id, 'label' => $c->label(), 'type' => $c->type->value, 'powerKw' => (float) $c->powerKw(),
                'maxMinutes' => $svc->maxMinutes($c), 'freeUntil' => $svc->freeUntil($c)?->timezone($tz)->format('g:i A'),
                'price' => ($t = $station->currentTariff($c->charger->type->value, $c->charger_id))
                    ? '₹'.rtrim(rtrim(number_format((float) $t->rate_per_kwh, 2), '0'), '.').'/kWh'.((float) $t->session_fee > 0 ? ' + ₹'.rtrim(rtrim(number_format((float) $t->session_fee, 2), '0'), '.').' session fee' : '').' + '.(float) $t->gst_rate.'% GST'
                    : null,
            ])->filter(fn ($c) => $c['maxMinutes'] >= 10)->values();

        return [
            'connectors' => $connectors, 'durations' => [30, 45, 60, 90, 120, 180],
        ];
    }

    public function reading(Request $request, ChargingSession $session): RedirectResponse
    {
        $d = $request->validate(['register' => ['nullable', 'numeric', 'min:0'], 'energy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'power' => ['nullable', 'numeric', 'min:0', 'max:1000'], 'soc' => ['nullable', 'integer', 'min:0', 'max:100']]);
        $this->sessions->addReading($session, $request->user(), isset($d['register']) ? (string) $d['register'] : null, isset($d['energy']) ? (string) $d['energy'] : null,
            isset($d['power']) ? (string) $d['power'] : null, isset($d['soc']) ? (int) $d['soc'] : null);

        return back()->with('success', 'Reading saved.');
    }

    public function scan(Request $request): RedirectResponse
    {
        abort_unless(self::canUse($request->user()), 403);
        $input = (string) $request->validate(['input' => ['required', 'string', 'max:500']])['input'];
        $booking = $this->checkIns->resolve($input);
        Gate::authorize('checkIn', $booking);
        $this->checkIns->checkIn($booking, str_contains($input, '/checkin/') ? 'staff_scan' : 'manual', $request->user());

        return back()->with('success', "{$booking->code} checked in · ".($booking->vehicle_snapshot['registration_no'] ?? '').' · charger '.$booking->charger?->code);
    }

    public function checkIn(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('checkIn', $booking);
        $this->checkIns->checkIn($booking, 'manual', $request->user());

        return back()->with('success', "{$booking->code} checked in.");
    }

    public function start(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate(['meter_start' => ['nullable', 'numeric', 'min:0', 'max:99999999']]);
        $this->sessions->startForBooking($booking, $request->user(), isset($data['meter_start']) ? (string) $data['meter_start'] : null);

        return back()->with('success', "Charging started for {$booking->code}.");
    }

    public function stop(Request $request, ChargingSession $session): RedirectResponse
    {
        $data = $request->validate([
            'meter_stop' => ['nullable', 'numeric', 'min:0', 'max:99999999'], 'energy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'reason' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $s = $this->sessions->stop($session, $request->user(), isset($data['meter_stop']) ? (string) $data['meter_stop'] : null,
            isset($data['energy']) ? (string) $data['energy'] : null, $data['reason'], $data['note'] ?? null);

        return back()->with('success', 'Charging stopped: '.number_format((float) $s->energy_kwh, 2).' kWh in '.$s->minutes().' min.');
    }

    public function noShow(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('staffCancel', $booking);
        app(RefundService::class)->markNoShow($booking, $request->user());

        return back()->with('success', "{$booking->code} marked as no-show.");
    }

    public function connectorStatus(Request $request, Connector $connector): RedirectResponse
    {
        $user = $request->user();
        $connector->load('charger');
        abort_unless($user->can('stations.status') && ChargingSessionService::worksAt($user, $connector->charger->station_id), 403);
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', self::MANUAL_STATUSES)], 'note' => ['nullable', 'string', 'max:255']]);
        if ($this->sessions->active($connector) && $data['status'] !== 'faulted') {
            throw ValidationException::withMessages(['status' => 'A session is running on this connector. Stop it first.']);
        }
        app(ConnectorStatusService::class)->setLive($connector, ConnectorLiveStatus::from($data['status']), 'staff', $data['note'] ?: null, $user->id);

        return back()->with('success', $connector->label().' is now '.ConnectorLiveStatus::from($data['status'])->getLabel().'.');
    }

    private function arrivals(Station $station, User $user): array
    {
        $tz = Station::TIMEZONE;
        $noShow = BusinessRules::int('booking.no_show_minutes');
        $sessions = ChargingSession::query()->where('station_id', $station->id)->whereNotNull('booking_id')
            ->where('started_at', '>=', now($tz)->startOfDay()->utc())->get()->keyBy('booking_id');

        return Booking::query()->where('station_id', $station->id)
            ->whereBetween('start_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()])
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow])
            ->with(['charger', 'connectorPoint.charger', 'payments', 'invoice'])->orderBy('start_at')->get()
            ->map(function (Booking $b) use ($tz, $sessions, $noShow, $user) {
                $s = $sessions->get($b->id);

                return [
                    'code' => $b->code, 'status' => $b->status->value, 'statusLabel' => $b->status->getLabel(),
                    'start' => $b->start_at->timezone($tz)->format('g:i A'), 'end' => $b->end_at->timezone($tz)->format('g:i A'),
                    'startIso' => $b->start_at->toIso8601String(), 'endIso' => $b->end_at->toIso8601String(),
                    'customer' => $b->customer_snapshot['name'] ?? null, 'phone' => $b->customer_snapshot['phone'] ?? null,
                    'vehicle' => trim(($b->vehicle_snapshot['make'] ?? '').' '.($b->vehicle_snapshot['model'] ?? '')), 'registration' => $b->vehicle_snapshot['registration_no'] ?? null,
                    'charger' => $b->charger?->code, 'connector' => $b->connectorPoint?->label(),
                    'batteryFrom' => $b->battery_from, 'batteryTo' => $b->battery_to, 'estKwh' => (float) $b->est_kwh,
                    'checkedInAt' => $b->checked_in_at?->timezone($tz)->format('g:i A'),
                    'blocker' => $b->status === BookingStatus::Confirmed ? $this->checkIns->blocker($b) : null,
                    'source' => $b->source, 'settlement' => $b->settlement,
                    'bill' => $b->source === 'walk_in' && $b->status === BookingStatus::Completed ? [
                        'kind' => 'walk_in', 'total' => (float) $b->total_amount, 'due' => (float) $b->total_amount, 'taxable' => (float) $b->taxable_amount,
                        'tax' => (float) $b->cgst_amount + (float) $b->sgst_amount + (float) $b->igst_amount,
                        'lines' => collect($b->price_lines ?? [])->map(fn ($l) => ['label' => $l['label'], 'qty' => (float) $l['qty'], 'unit' => $l['unit'], 'amount' => (float) $l['amount']])->values(),
                        'paidBy' => ($p = $b->payments->where('purpose', 'booking')->firstWhere('status', \App\Models\Payment::SUCCESS)) ? $p->payment_mode.($p->bank_rrn ? ' · '.$p->bank_rrn : '') : null,
                        'invoiceUrl' => $b->invoice ? route('bookings.invoice', $b) : null, 'prepaid' => null, 'outcome' => null,
                    ] : ($b->trueup_status !== null ? [
                        'kind' => 'final', 'total' => (float) $b->final_total, 'due' => (float) ($b->balance_due ?? 0), 'prepaid' => (float) $b->total_amount,
                        'taxable' => null, 'tax' => null,
                        'lines' => collect($b->final_lines ?? [])->map(fn ($l) => ['label' => $l['label'], 'qty' => (float) $l['qty'], 'unit' => $l['unit'], 'amount' => (float) $l['amount']])->values(),
                        'outcome' => \App\Services\StationLabels::trueUp($b),
                        'paidBy' => ($p = $b->payments->where('status', \App\Models\Payment::SUCCESS)->firstWhere('purpose', 'balance')) ? $p->payment_mode.($p->bank_rrn ? ' · '.$p->bank_rrn : '') : null,
                        'invoiceUrl' => $b->invoice ? route('bookings.invoice', $b) : null,
                    ] : null),
                    'canNoShow' => $b->status === BookingStatus::Confirmed && now()->gte($b->start_at->copy()->addMinutes($noShow)) && $user->can('staffCancel', $b),
                    'session' => $s ? [
                        'uuid' => $s->uuid, 'status' => $s->status, 'startedAt' => $s->started_at->timezone($tz)->format('g:i A'), 'startedIso' => $s->started_at->toIso8601String(),
                        'meterStart' => $s->meter_start_kwh !== null ? (float) $s->meter_start_kwh : null, 'energy' => $s->energy_kwh !== null ? (float) $s->energy_kwh : null,
                        'minutes' => $s->minutes(),
                        'lastReading' => ($m = $s->meterValues()->latest('measured_at')->first()) ? ['at' => $m->measured_at->timezone($tz)->format('g:i A'), 'kwh' => $m->energy_kwh !== null ? (float) $m->energy_kwh : null, 'soc' => $m->soc, 'power' => $m->power_kw !== null ? (float) $m->power_kw : null] : null,
                    ] : null,
                ];
            })->values()->all();
    }

    private function chargers(Station $station): array
    {
        $tz = Station::TIMEZONE;
        $active = ChargingSession::query()->where('station_id', $station->id)->where('status', 'active')->with('booking')->get()->keyBy('connector_id');
        $next = Booking::query()->where('station_id', $station->id)->where('status', BookingStatus::Confirmed)->where('end_at', '>', now())
            ->orderBy('start_at')->get(['connector_id', 'start_at', 'code'])->groupBy('connector_id')->map->first();

        return $station->chargers()->with('connectorPoints')->orderBy('code')->get()->map(fn ($c) => [
            'code' => $c->code, 'type' => $c->type->value, 'powerKw' => (float) $c->power_kw,
            'connectors' => $c->connectorPoints->map(fn (Connector $k) => [
                'id' => $k->id, 'no' => $k->connector_no, 'type' => $k->type->getLabel(),
                'live' => $k->live_status?->value, 'liveLabel' => $k->live_status?->getLabel(), 'tone' => $k->live_status?->getColor(),
                'enabled' => $k->status?->value === 'online', 'note' => $k->live_note,
                'since' => $k->live_status_at?->timezone($tz)->format('g:i A'),
                'session' => ($s = $active->get($k->id)) ? ['uuid' => $s->uuid, 'booking' => $s->booking?->code, 'registration' => $s->booking?->vehicle_snapshot['registration_no'] ?? null,
                    'startedIso' => $s->started_at->toIso8601String(), 'meterStart' => $s->meter_start_kwh !== null ? (float) $s->meter_start_kwh : null] : null,
                'next' => ($n = $next->get($k->id)) ? ['code' => $n->code, 'at' => $n->start_at->timezone($tz)->format($n->start_at->timezone($tz)->isToday() ? 'g:i A' : 'D j M, g:i A')] : null,
            ])->values(),
        ])->values()->all();
    }
}

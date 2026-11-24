<?php

namespace App\Http\Controllers;

use App\Enums\ConnectorType;
use App\Models\Connector;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\WaitlistEntry;
use App\Services\AvailabilityService;
use App\Services\WaitlistService;
use App\Support\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** C7: join a station's waiting list, see your place and offers, leave. */
class WaitlistController extends Controller
{
    public function __construct(private readonly WaitlistService $waitlist, private readonly AvailabilityService $availability) {}

    public function create(Request $request, Station $station): Response
    {
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $user = $request->user();
        $types = Connector::query()->whereHas('charger', fn ($q) => $q->where('station_id', $station->id))->pluck('type')->map(fn ($t) => $t->value)->unique()->values();
        $rules = RuleSet::activeFor($station);

        return Inertia::render('waitlist/create', [
            'station' => ['name' => $station->name, 'slug' => $station->slug, 'city' => $station->city, 'stateCode' => $station->state_code],
            'dates' => collect($this->availability->bookableDates())->map(fn ($d) => ['value' => $d, 'label' => Carbon::parse($d, Station::TIMEZONE)->format('D j M')])->values(),
            'durations' => $this->availability->durations($station),
            'connectors' => $types->map(fn ($t) => ['value' => $t, 'label' => ConnectorType::from($t)->getLabel()])->values(),
            'vehicles' => $user->vehicles()->get(['make', 'model', 'registration_no', 'battery_kwh', 'connector'])->map(fn ($v) => [
                'make' => $v->make, 'model' => $v->model, 'registration_no' => $v->registration_no, 'battery_kwh' => (float) $v->battery_kwh, 'connector' => $v->connector instanceof \BackedEnum ? $v->connector->value : $v->connector,
            ])->values(),
            'customer' => ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone],
            'states' => collect(IndianStates::STATES)->map(fn ($n, $c) => ['value' => (string) $c, 'label' => $n])->values(),
            'rules' => $rules ? ['id' => $rules->id, 'version' => $rules->version, 'html' => \App\Support\PublicHtml::clean($rules->content)] : null,
            'claimMinutes' => \App\Support\BusinessRules::int('waitlist.claim_minutes'),
            'prefill' => ['date' => $request->query('date'), 'duration' => (int) $request->query('duration')],
        ]);
    }

    public function store(Request $request, Station $station): RedirectResponse
    {
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $request->merge([
            'registration_no' => strtoupper(preg_replace('/[\s-]+/', '', (string) $request->input('registration_no'))),
            'phone' => preg_replace('/\D/', '', (string) $request->input('phone')),
            'gstin' => $request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null,
        ]);
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'window_from' => ['required', 'date_format:H:i'], 'window_to' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer'], 'connector' => ['required', Rule::enum(ConnectorType::class)],
            'make' => ['required', 'string', 'max:60'], 'model' => ['required', 'string', 'max:80'],
            'registration_no' => ['required', 'string', 'regex:/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$|^[0-9]{2}BH[0-9]{4}[A-Z]{1,2}$/'],
            'battery_kwh' => ['required', 'decimal:0,2', 'between:1,200'], 'battery_from' => ['required', 'integer', 'between:0,99'], 'battery_to' => ['required', 'integer', 'between:1,100', 'gt:battery_from'],
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'gstin' => ['nullable', new \App\Rules\ValidGstin], 'state_code' => ['required', Rule::in(array_keys(IndianStates::STATES))],
            'accept_rules' => ['accepted'],
        ], [
            'registration_no.regex' => 'Enter a valid Indian registration number, e.g. MH12AB1234.', 'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'accept_rules.accepted' => 'Please read and accept the station rules.', 'battery_to.gt' => 'Target charge must be higher than your current charge.',
        ]);
        $entry = $this->waitlist->join($request->user(), $station, $d);

        return to_route('waitlist.index')->with('success', "You’re on the waiting list for {$station->name}. You’re number ".$this->waitlist->position($entry).' in line.');
    }

    public function index(Request $request): Response
    {
        $tz = Station::TIMEZONE;
        $entries = WaitlistEntry::query()->where('user_id', $request->user()->id)->with(['station', 'booking'])->latest('id')->limit(30)->get();

        return Inertia::render('waitlist/index', [
            'entries' => $entries->map(fn (WaitlistEntry $e) => [
                'uuid' => $e->uuid, 'status' => $e->status, 'statusLabel' => WaitlistEntry::STATUSES[$e->status] ?? $e->status,
                'station' => ['name' => $e->station->name, 'slug' => $e->station->slug, 'city' => $e->station->city],
                'date' => $e->date->format('D, j M'), 'window' => Carbon::parse($e->window_from)->format('g:i A').' – '.Carbon::parse($e->window_to)->format('g:i A'),
                'duration' => $e->duration, 'vehicle' => trim(($e->vehicle['make'] ?? '').' '.($e->vehicle['model'] ?? '')).' · '.($e->vehicle['registration_no'] ?? ''),
                'position' => $this->waitlist->position($e), 'estimate' => $e->status === 'waiting' ? $this->waitlist->estimate($e)?->timezone($tz)->format('g:i A') : null,
                'offer' => $e->status === 'offered' && $e->booking ? [
                    'code' => $e->booking->code, 'slot' => $e->booking->start_at->timezone($tz)->format('D j M, g:i A'),
                    'expiresAt' => $e->offer_expires_at?->toIso8601String(), 'amount' => (float) $e->booking->total_amount,
                ] : null,
                'bookingCode' => $e->status === 'booked' ? $e->booking?->code : null, 'closedReason' => $e->closed_reason,
            ])->values(),
        ]);
    }

    public function leave(Request $request, WaitlistEntry $entry): RedirectResponse
    {
        $this->waitlist->leave($entry, $request->user());

        return back()->with('success', 'You have left the waiting list.');
    }
}

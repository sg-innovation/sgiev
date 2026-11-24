<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ConnectorType;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Customers manage their saved vehicles (used to pre-fill bookings). */
class VehicleController extends Controller
{
    public const MAX_VEHICLES = 10;

    public function index(Request $request): Response
    {
        return Inertia::render('settings/vehicles', [
            'vehicles' => $request->user()->vehicles()->reorder()->orderByDesc('is_default')->orderBy('make')->get()->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'make' => $v->make,
                'model' => $v->model,
                'registration_no' => $v->registration_no,
                'battery_kwh' => (float) $v->battery_kwh,
                'connector' => $v->connector->value,
                'connectorLabel' => $v->connector->getLabel(),
                'is_default' => $v->is_default,
            ]),
            'connectors' => collect(ConnectorType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->getLabel()]),
            'max' => self::MAX_VEHICLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->vehicles()->count() >= self::MAX_VEHICLES) {
            return back()->withErrors(['registration_no' => 'You can save up to '.self::MAX_VEHICLES.' vehicles.']);
        }
        $data = $this->validated($request);
        $vehicle = $request->user()->vehicles()->create($data);
        $this->applyDefault($request, $vehicle, (bool) ($data['is_default'] ?? false));

        return back()->with('success', 'Vehicle added.');
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 403);
        $data = $this->validated($request, $vehicle);
        $vehicle->update($data);
        $this->applyDefault($request, $vehicle, (bool) ($data['is_default'] ?? false));

        return back()->with('success', 'Vehicle updated.');
    }

    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 403);
        $vehicle->delete(); // past bookings keep their own vehicle snapshot

        return back()->with('success', 'Vehicle removed.');
    }

    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $request->merge(['registration_no' => strtoupper(preg_replace('/[\s-]+/', '', (string) $request->input('registration_no')))]);

        return $request->validate([
            'make' => ['required', 'string', 'max:60', 'not_regex:/[<>]/'],
            'model' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'registration_no' => [
                'required', 'string', 'regex:/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$|^[0-9]{2}BH[0-9]{4}[A-Z]{1,2}$/',
                Rule::unique('vehicles')->where('user_id', $request->user()->id)->ignore($vehicle?->id),
            ],
            'battery_kwh' => ['required', 'decimal:0,2', 'between:1,200'],
            'connector' => ['required', Rule::enum(ConnectorType::class)],
            'is_default' => ['boolean'],
        ], [
            'registration_no.regex' => 'Enter a valid Indian registration number, e.g. MH12AB1234 or 22BH1234AA.',
            'registration_no.unique' => 'You have already saved this vehicle.',
        ]);
    }

    private function applyDefault(Request $request, Vehicle $vehicle, bool $makeDefault): void
    {
        $user = $request->user();
        if ($makeDefault || ! $user->vehicles()->where('is_default', true)->exists()) {
            DB::transaction(function () use ($user, $vehicle) {
                $user->vehicles()->whereKeyNot($vehicle->id)->update(['is_default' => false]);
                $vehicle->forceFill(['is_default' => true])->save();
            });
        }
    }
}

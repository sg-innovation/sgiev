<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConnectorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\DeviceToken;
use App\Models\Vehicle;
use App\Services\AvatarService;
use App\Services\Messaging\Channels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The signed-in customer: profile, photo, notification choices, push devices and saved vehicles. */
class AccountController extends Controller
{
    public const MAX_VEHICLES = 10;

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $u = $request->user();
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', 'not_regex:/[<>]/'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($u->id)],
            'city' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[\pL .\-\']+$/u'],
        ]);
        $u->fill($d);
        if ($u->isDirty('email')) {
            $u->email_verified_at = null;
        }
        $u->save();
        if ($u->wasChanged('email')) {
            $u->sendEmailVerificationNotification();
        }

        return new UserResource($u->fresh());
    }

    /** POST /me/avatar (multipart, field "photo"). */
    public function avatar(Request $request, AvatarService $avatars): UserResource
    {
        $request->validate(['photo' => AvatarService::RULES]);
        $avatars->store($request->user(), $request->file('photo'));

        return new UserResource($request->user()->fresh());
    }

    public function removeAvatar(Request $request, AvatarService $avatars): UserResource
    {
        $avatars->remove($request->user());

        return new UserResource($request->user()->fresh());
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['data' => Channels::prefs($request->user())]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $d = $request->validate(['sms' => ['required', 'boolean'], 'whatsapp' => ['required', 'boolean'], 'push' => ['required', 'boolean']]);
        $u = $request->user();
        $u->forceFill(['notification_prefs' => array_map('boolval', $d) + (array) ($u->notification_prefs ?? [])])->save();

        return response()->json(['data' => Channels::prefs($u->fresh())]);
    }

    /** POST /me/devices – register this phone's Firebase push token. */
    public function registerDevice(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:500'], 'platform' => ['required', Rule::in(['android', 'ios', 'web'])]]);
        DeviceToken::query()->updateOrCreate(['token' => $d['token']], ['user_id' => $request->user()->id, 'platform' => $d['platform'], 'last_seen_at' => now()]);

        return response()->json(['message' => 'Device registered.'], 201);
    }

    public function removeDevice(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:500']]);
        DeviceToken::query()->where('user_id', $request->user()->id)->where('token', $d['token'])->delete();

        return response()->json(['message' => 'Device removed.']);
    }

    // ── Vehicles ────────────────────────────────────────────────────────────────────────────────────────────

    public function vehicles(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->vehicles()->orderByDesc('is_default')->orderBy('id')->get()->map(fn (Vehicle $v) => self::vehicle($v))]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        if ($request->user()->vehicles()->count() >= self::MAX_VEHICLES) {
            throw ValidationException::withMessages(['registration_no' => 'You can save up to '.self::MAX_VEHICLES.' vehicles.']);
        }
        $data = $this->validVehicle($request);
        $v = $request->user()->vehicles()->create($data);
        $this->applyDefault($request, $v, (bool) ($data['is_default'] ?? false));

        return response()->json(['data' => self::vehicle($v->fresh())], 201);
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
        $data = $this->validVehicle($request, $vehicle);
        $vehicle->update($data);
        $this->applyDefault($request, $vehicle, (bool) ($data['is_default'] ?? false));

        return response()->json(['data' => self::vehicle($vehicle->fresh())]);
    }

    public function destroyVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->user_id === $request->user()->id, 404);
        $vehicle->delete();

        return response()->json(['message' => 'Vehicle removed.']);
    }

    public static function vehicle(Vehicle $v): array
    {
        return ['id' => $v->id, 'make' => $v->make, 'model' => $v->model, 'registration_no' => $v->registration_no,
            'battery_kwh' => (float) $v->battery_kwh, 'connector' => $v->connector?->value ?? $v->connector, 'is_default' => (bool) $v->is_default];
    }

    private function validVehicle(Request $request, ?Vehicle $vehicle = null): array
    {
        $request->merge(['registration_no' => strtoupper(preg_replace('/[\s-]+/', '', (string) $request->input('registration_no')))]);

        return $request->validate([
            'make' => ['required', 'string', 'max:60', 'not_regex:/[<>]/'],
            'model' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'registration_no' => ['required', 'string', 'regex:/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$|^[0-9]{2}BH[0-9]{4}[A-Z]{1,2}$/',
                Rule::unique('vehicles')->where('user_id', $request->user()->id)->ignore($vehicle?->id)],
            'battery_kwh' => ['required', 'decimal:0,2', 'between:1,200'],
            'connector' => ['required', Rule::enum(ConnectorType::class)],
            'is_default' => ['boolean'],
        ], ['registration_no.regex' => 'Enter a valid Indian registration number, e.g. MH12AB1234 or 22BH1234AA.', 'registration_no.unique' => 'You have already saved this vehicle.']);
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

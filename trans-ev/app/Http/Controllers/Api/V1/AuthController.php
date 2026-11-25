<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\TwoFactorService;
use App\Support\BusinessRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * App sign-in. Mobile OTP (sign up or log in with a phone number) or email + password. Accounts with two-step login
 * on get a short-lived challenge token first and must send their authenticator (or recovery) code.
 * Tokens are Sanctum personal access tokens: send "Authorization: Bearer <token>" on every other call.
 */
class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp, private readonly TwoFactorService $twoFactor) {}

    /** POST /auth/otp – send a 6-digit code by SMS. */
    public function requestOtp(Request $request): JsonResponse
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = OtpService::normalise($d['phone']);
        $result = $this->otp->send($d['phone'], 'login', $request->ip());

        return response()->json(['message' => 'Code sent by SMS.', 'phone' => $phone, 'is_registered' => $phone !== null && User::query()->where('phone', $phone)->exists()] + $result);
    }

    /** POST /auth/otp/verify – log in (or create the account) with the code. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $d = $request->validate([
            'phone' => ['required', 'string', 'max:20'], 'code' => ['required', 'string', 'size:6'], 'device_name' => ['required', 'string', 'max:60'],
            'name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:255'],
        ]);
        [$user, $isNew] = app(\App\Services\Auth\OtpLoginService::class)->login($d['phone'], $d['code'], $d['name'] ?? null, $d['email'] ?? null);

        return $this->issue($user, $d['device_name'], $isNew);
    }

    /** POST /auth/login – email and password. */
    public function login(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:60']]);
        $user = User::query()->where('email', strtolower($d['email']))->first();
        if (! $user || ! Hash::check($d['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }

        return $this->issue($user, $d['device_name'], false);
    }

    /** POST /auth/two-factor – finish sign-in with an authenticator or recovery code. */
    public function twoFactor(Request $request): JsonResponse
    {
        $d = $request->validate(['challenge_token' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        try {
            $c = json_decode(Crypt::decryptString($d['challenge_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['challenge_token' => 'Start signing in again.']);
        }
        if (($c['exp'] ?? 0) < time() || ! ($user = User::query()->find($c['uid'] ?? 0))) {
            throw ValidationException::withMessages(['challenge_token' => 'This sign-in has expired. Start again.']);
        }
        if (! $this->twoFactor->verifyUser($user, $d['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        return $this->token($user, (string) $c['device'], (bool) ($c['new'] ?? false));
    }

    /** POST /auth/logout – revoke this token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function issue(User $user, string $device, bool $isNew): JsonResponse
    {
        if ($user->blocked_at) {
            abort(403, 'This account is blocked. Contact support.');
        }
        if (TwoFactorService::enabled($user)) {
            $challenge = Crypt::encryptString(json_encode(['uid' => $user->id, 'device' => $device, 'new' => $isNew, 'exp' => time() + 300]));

            return response()->json(['two_factor_required' => true, 'challenge_token' => $challenge, 'message' => 'Enter the 6-digit code from your authenticator app.']);
        }

        return $this->token($user, $device, $isNew);
    }

    private function token(User $user, string $device, bool $isNew): JsonResponse
    {
        $days = max(1, BusinessRules::int('api.token_days'));
        $token = $user->createToken(mb_substr($device, 0, 60), ['*'], now()->addDays($days));
        activity()->performedOn($user)->causedBy($user)->withProperties(['device' => $device])->log('signed in on the app');

        return response()->json([
            'token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'is_new' => $isNew, 'user' => new UserResource($user->fresh()),
        ], $isNew ? 201 : 200);
    }
}

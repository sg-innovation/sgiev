<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OtpLoginService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Website "Log in with mobile": SMS code, then sign in (or create the account). Two-step login still applies. */
class OtpLoginController extends Controller
{
    public function send(Request $request, OtpService $otp): JsonResponse
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = OtpService::normalise($d['phone']);
        $result = $otp->send($d['phone'], 'login', $request->ip());

        return response()->json(['phone' => $phone, 'is_registered' => $phone !== null && User::query()->where('phone', $phone)->exists()] + $result);
    }

    public function verify(Request $request, OtpLoginService $login): RedirectResponse
    {
        $d = $request->validate([
            'phone' => ['required', 'string', 'max:20'], 'code' => ['required', 'string', 'size:6'],
            'name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:255'], 'remember' => ['boolean'],
        ]);
        [$user] = $login->login($d['phone'], $d['code'], $d['name'] ?? null, $d['email'] ?? null);

        return TwoFactorChallengeController::completeLogin($request, $user, (bool) ($d['remember'] ?? false));
    }
}

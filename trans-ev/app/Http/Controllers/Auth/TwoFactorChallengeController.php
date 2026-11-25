<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Second step of website sign-in for accounts with an authenticator app (after password or mobile code). */
class TwoFactorChallengeController extends Controller
{
    private const KEY = 'login.two_factor';

    /** Sign the user in, or park them at the two-step page when their account has an authenticator app. */
    public static function completeLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        if (TwoFactorService::enabled($user)) {
            Auth::guard('web')->logout();
            $request->session()->put(self::KEY, ['id' => $user->id, 'remember' => $remember, 'at' => now()->getTimestamp()]);

            return redirect()->route('two-factor.challenge');
        }
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function create(Request $request): Response|RedirectResponse
    {
        return $this->pending($request) ? Inertia::render('auth/two-factor-challenge') : redirect()->route('login');
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $pending = $this->pending($request) ?? throw ValidationException::withMessages(['code' => 'Your sign-in expired. Please start again.']);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $user = User::query()->find($pending['id']);
        if (! $user || ! $twoFactor->verifyUser($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Use the 6-digit code from your app, or a recovery code.']);
        }
        $request->session()->forget(self::KEY);
        Auth::guard('web')->login($user, (bool) $pending['remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function pending(Request $request): ?array
    {
        $p = $request->session()->get(self::KEY);

        return is_array($p) && ($p['at'] ?? 0) >= now()->subMinutes(10)->getTimestamp() ? $p : null;
    }
}

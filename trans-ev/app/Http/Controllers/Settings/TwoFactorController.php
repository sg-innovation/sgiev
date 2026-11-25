<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use App\Support\BusinessRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Two-step login: turn the authenticator app on or off, and new recovery codes. */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function edit(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('settings/two-factor', [
            'enabled' => TwoFactorService::enabled($u),
            'confirmedAt' => $u->two_factor_confirmed_at?->timezone('Asia/Kolkata')->format('j M Y'),
            'recoveryLeft' => count((array) ($u->two_factor_recovery_codes ?? [])),
            'required' => $u->isStaff() && BusinessRules::int('security.staff_2fa_required') === 1,
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes'),
        ]);
    }

    /** Start: a new secret (kept in the session until confirmed) and its QR code. */
    public function setup(Request $request): JsonResponse
    {
        abort_if(TwoFactorService::enabled($request->user()), 409, 'Two-step login is already on.');
        $secret = $this->twoFactor->newSecret();
        $request->session()->put('two_factor.pending', $secret);

        return response()->json(['qr' => $this->twoFactor->qrDataUri($request->user(), $secret), 'secret' => $secret]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'digits:6']])['code'];
        $secret = (string) $request->session()->get('two_factor.pending');
        if ($secret === '' || ! $this->twoFactor->verify($secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }
        $codes = $this->twoFactor->enable($request->user(), $secret);
        $request->session()->forget('two_factor.pending');

        return back()->with('two_factor.recovery_codes', $codes)->with('success', 'Two-step login is on.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $this->checkPassword($request);
        abort_unless(TwoFactorService::enabled($request->user()), 409);

        return back()->with('two_factor.recovery_codes', $this->twoFactor->regenerateRecoveryCodes($request->user()))->with('success', 'New recovery codes made. The old ones no longer work.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->checkPassword($request);
        $u = $request->user();
        if ($u->isStaff() && BusinessRules::int('security.staff_2fa_required') === 1) {
            throw ValidationException::withMessages(['password' => 'Staff accounts must keep two-step login on.']);
        }
        $this->twoFactor->disable($u);

        return back()->with('success', 'Two-step login is off.');
    }

    private function checkPassword(Request $request): void
    {
        $password = (string) $request->validate(['password' => ['required', 'string']])['password'];
        if (! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }
    }
}

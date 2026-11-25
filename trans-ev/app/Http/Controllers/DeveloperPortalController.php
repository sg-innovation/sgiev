<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ApiDeveloper;
use App\Models\User;
use App\Support\ApiDocs;
use App\Support\BusinessRules;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Developer portal (/developers): API documentation and a live console for the app developers. Trans EV creates a
 * developer in Admin → Settings → API developers with a 6-digit PIN; the developer enters it here. Access lasts
 * a working day; wrong PINs are limited per device and across the whole portal.
 */
class DeveloperPortalController extends Controller
{
    private const SESSION_HOURS = 8;

    public static function current(Request $request): ?ApiDeveloper
    {
        $id = $request->session()->get('developer_portal.id');
        $at = (int) $request->session()->get('developer_portal.at', 0);
        if (! $id || $at < now()->subHours(self::SESSION_HOURS)->getTimestamp()) {
            return null;
        }
        $dev = ApiDeveloper::query()->find($id);

        return $dev?->canEnter() ? $dev : null;
    }

    public function show(Request $request): Response
    {
        $dev = self::current($request);
        if (! $dev) {
            return Inertia::render('developers/unlock')->withViewData(['noindex' => true]);
        }

        return Inertia::render('developers/portal', [
            'developer' => ['name' => $dev->name, 'company' => $dev->company],
            'baseUrl' => url('/api/v1'),
            'groups' => ApiDocs::groups(),
            'sandboxTokens' => BusinessRules::int('api.sandbox_tokens') === 1,
            'testPhone' => filled(Settings::get('auth.test_phone')) ? (string) Settings::get('auth.test_phone') : null,
            'otpDebug' => app()->environment('local', 'testing'),
        ])->withViewData(['noindex' => true]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $pin = (string) $request->validate(['pin' => ['required', 'digits:6']])['pin'];
        $globalKey = 'developer_portal:failures';
        if ((int) Cache::get($globalKey, 0) >= 50) {
            throw ValidationException::withMessages(['pin' => 'The portal is locked for a few minutes after too many wrong PINs. Try again later.']);
        }
        $dev = ApiDeveloper::query()->where('is_active', true)->get()->first(fn (ApiDeveloper $d) => $d->canEnter() && Hash::check($pin, $d->pin_hash));
        if (! $dev) {
            Cache::add($globalKey, 0, now()->addMinutes(15));
            Cache::increment($globalKey);
            throw ValidationException::withMessages(['pin' => 'That PIN is not right, or access has expired. Ask Trans EV for a new one.']);
        }
        $request->session()->regenerate();
        $request->session()->put(['developer_portal.id' => $dev->id, 'developer_portal.at' => now()->getTimestamp()]);
        $dev->forceFill(['last_used_at' => now(), 'failed_attempts' => 0])->save();
        activity()->performedOn($dev)->withProperties(['ip' => $request->ip()])->log('developer portal opened');

        return redirect()->route('developers.show');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['developer_portal.id', 'developer_portal.at']);

        return redirect()->route('developers.show');
    }

    public function openApi(Request $request): JsonResponse
    {
        abort_unless(self::current($request), 403);

        return response()->json(ApiDocs::openApi(), 200, ['Content-Disposition' => 'attachment; filename="transev-api-v1.openapi.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A token for this developer's own sandbox customer account, so every signed-in endpoint can be tried at once.
     * Each call replaces the previous sandbox token. Can be turned off in Business rules.
     */
    public function sandboxToken(Request $request): JsonResponse
    {
        $dev = self::current($request) ?? abort(403);
        abort_unless(BusinessRules::int('api.sandbox_tokens') === 1, 403, 'Sandbox tokens are turned off. Sign in with OTP instead.');
        $user = $dev->testUser;
        if (! $user) {
            $user = User::query()->create(['name' => 'Sandbox – '.Str::limit($dev->name, 60, ''), 'email' => 'developer'.$dev->id.'.'.Str::lower(Str::random(6)).'@sandbox.invalid',
                'password' => Hash::make(Str::random(40))]);
            $user->forceFill(['email_verified_at' => now(), 'phone' => null])->save();
            $user->assignRole(Role::Customer->value);
            $dev->forceFill(['test_user_id' => $user->id])->save();
        }
        $user->tokens()->where('name', 'developer-portal')->delete();
        $token = $user->createToken('developer-portal', ['*'], now()->addDays(7));
        activity()->performedOn($dev)->log('sandbox API token issued');

        return response()->json(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at?->toIso8601String(), 'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]]);
    }
}

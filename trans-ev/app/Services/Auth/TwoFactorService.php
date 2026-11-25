<?php

namespace App\Services\Auth;

use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app two-step login (Google Authenticator, Microsoft Authenticator, Authy…), RFC 6238 TOTP.
 * The same secret works on the website, the admin panel (Filament app authentication) and the API.
 * Recovery codes are stored hashed and each works once. A code cannot be used twice (replay protection).
 */
class TwoFactorService
{
    public const WINDOW = 1; // ±30 s for clock drift

    public function __construct(private readonly Google2FA $g2fa = new Google2FA) {}

    public static function enabled(User $user): bool
    {
        return filled($user->two_factor_secret);
    }

    public function newSecret(): string
    {
        return $this->g2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        $issuer = (string) (config('app.name') ?: 'Trans EV');

        return $this->g2fa->getQRCodeUrl($issuer, $user->email ?: ($user->phone ?: 'user'.$user->id), $secret);
    }

    /** SVG data URI of the QR code to scan (generated on our server; the secret never leaves it otherwise). */
    public function qrDataUri(User $user, string $secret): string
    {
        return (new QRCode(new QROptions(['outputBase64' => true, 'svgAddXmlHeader' => false, 'scale' => 6])))->render($this->otpauthUrl($user, $secret));
    }

    /** Check a 6-digit code against a secret; each 30-second step is accepted once. */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = 'tfa:last:'.hash('sha256', $secret);
        $last = Cache::get($key);
        $timestamp = $this->g2fa->verifyKeyNewer($secret, $code, is_int($last) ? $last : null, self::WINDOW);
        if ($timestamp === false) {
            return false;
        }
        Cache::put($key, $timestamp === true ? (int) floor(time() / 30) : (int) $timestamp, now()->addMinutes(5));

        return true;
    }

    /** Code for a user: authenticator code, or one of their recovery codes (used up). */
    public function verifyUser(User $user, string $code): bool
    {
        if (! self::enabled($user)) {
            return false;
        }
        if ($this->verify((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return $this->useRecoveryCode($user, $code);
    }

    /** @return list<string> plain codes, shown once */
    public function enable(User $user, string $secret): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'two_factor_confirmed_at' => now(),
        ])->save();
        activity()->performedOn($user)->causedBy($user)->log('two-step login turned on');

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        activity()->performedOn($user)->causedBy(auth()->user() ?? $user)->log('two-step login turned off');
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    /** @return list<string> */
    public function newRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        $codes = (array) ($user->two_factor_recovery_codes ?? []);
        foreach ($codes as $i => $hash) {
            if (is_string($hash) && Hash::check($code, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                activity()->performedOn($user)->causedBy($user)->log('recovery code used');

                return true;
            }
        }

        return false;
    }
}

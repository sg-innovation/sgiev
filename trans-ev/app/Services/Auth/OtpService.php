<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Notifications\OtpMessage;
use App\Services\Messaging\SmsChannel;
use App\Support\BusinessRules;
use App\Support\Settings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * One-time login codes by SMS (website "Log in with mobile" and the app API). 6 digits, valid for a few minutes,
 * a few attempts, a resend cooldown and an hourly cap per number (all in Business rules). Codes are stored hashed
 * and never written to the notification log. A test number with a fixed code can be set in Integrations for app
 * store reviewers and developers.
 */
class OtpService
{
    /** Indian mobile (10 digits, starting 6–9) from any input such as +91 98765 43210. */
    public static function normalise(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        }

        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }

    /** @return array{expires_in: int, resend_in: int, debug_code?: string} */
    public function send(string $phoneInput, string $purpose = 'login', ?string $ip = null): array
    {
        $phone = self::normalise($phoneInput) ?? throw ValidationException::withMessages(['phone' => 'Enter a valid 10-digit Indian mobile number.']);
        $cooldown = max(10, BusinessRules::int('otp.resend_seconds'));
        $last = OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->latest('id')->first();
        if ($last && $last->created_at->gt(now()->subSeconds($cooldown))) {
            $wait = $cooldown - (int) $last->created_at->diffInSeconds(now(), true);
            throw ValidationException::withMessages(['phone' => "Please wait {$wait} seconds before asking for a new code."]);
        }
        if (OtpCode::query()->where('phone', $phone)->where('created_at', '>=', now()->subHour())->count() >= max(1, BusinessRules::int('otp.max_per_hour'))) {
            throw ValidationException::withMessages(['phone' => 'Too many codes asked for this number. Try again in an hour.']);
        }

        $test = $this->testCode($phone);
        $code = $test ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = max(1, BusinessRules::int('otp.valid_minutes'));
        OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::create(['phone' => $phone, 'purpose' => $purpose, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes($minutes), 'ip' => $ip]);

        if ($test === null) {
            (new SmsChannel)->send(new \App\Support\SmsRecipient($phone), new OtpMessage($code, $minutes));
        }

        return ['expires_in' => $minutes * 60, 'resend_in' => $cooldown]
            + (app()->environment('local', 'testing') && $test === null ? ['debug_code' => $code] : []);
    }

    /** True once for the right code; wrong codes count towards the attempt limit. */
    public function verify(string $phoneInput, string $code, string $purpose = 'login'): bool
    {
        $phone = self::normalise($phoneInput);
        $code = preg_replace('/\D/', '', $code);
        if (! $phone || strlen($code) !== 6) {
            return false;
        }
        $otp = OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')
            ->where('expires_at', '>', now())->latest('id')->first();
        if (! $otp || $otp->attempts >= max(1, BusinessRules::int('otp.max_attempts'))) {
            return false;
        }
        $otp->increment('attempts');
        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }
        $otp->forceFill(['consumed_at' => now()])->save();

        return true;
    }

    private function testCode(string $phone): ?string
    {
        $testPhone = self::normalise((string) Settings::get('auth.test_phone'));
        $testCode = (string) Settings::get('auth.test_otp');

        return $testPhone && $testPhone === $phone && preg_match('/^\d{6}$/', $testCode) ? $testCode : null;
    }
}

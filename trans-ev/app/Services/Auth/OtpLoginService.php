<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Log in with mobile" for the website and the app: checks the SMS code and returns the account for that number,
 * creating a customer account for a new number (name and email required). An email that already belongs to another
 * account is never taken over: that person must log in with their email and add the number to their profile.
 */
class OtpLoginService
{
    public function __construct(private readonly OtpService $otp) {}

    /** @return array{0: User, 1: bool} the user and whether the account was just created */
    public function login(string $phoneInput, string $code, ?string $name = null, ?string $email = null): array
    {
        $phone = OtpService::normalise($phoneInput) ?? throw ValidationException::withMessages(['phone' => 'Enter a valid 10-digit Indian mobile number.']);
        $users = User::query()->where('phone', $phone)->get();
        if ($users->count() > 1) {
            throw ValidationException::withMessages(['phone' => 'More than one account uses this number. Log in with your email and password.']);
        }
        $user = $users->first();
        $email = $email ? strtolower(trim($email)) : null;
        if (! $user && (blank($name) || blank($email))) {
            throw ValidationException::withMessages(['name' => 'New here? Enter your name and email to create your account.']);
        }
        if (! $user && User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has an account. Log in with email and password, then add this mobile number in your profile.']);
        }
        if (! $this->otp->verify($phone, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }
        if ($user?->blocked_at) {
            throw ValidationException::withMessages(['phone' => 'This account is blocked. Contact support.']);
        }
        $isNew = false;
        if (! $user) {
            $user = User::query()->create(['name' => trim(strip_tags((string) $name)), 'email' => $email, 'password' => Hash::make(Str::random(40))]);
            $user->forceFill(['phone' => $phone])->save();
            $user->assignRole(Role::Customer->value);
            $user->sendEmailVerificationNotification();
            $isNew = true;
        }
        $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now()])->save();

        return [$user, $isNew];
    }
}

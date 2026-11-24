<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-managed integration settings (encrypted) override .env values.
        \App\Support\Settings::applyToConfig();

        // Account emails use the database message templates.
        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(fn ($user, string $url) => \App\Support\Messages::mail('auth.verify_email', ['name' => $user->name ?? ''], $url));
        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(fn ($user, string $token) => \App\Support\Messages::mail('auth.reset_password', [
            'name' => $user->name ?? '', 'expire_minutes' => (string) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ], url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false))));

        // Strong password policy (security audit: ASVS V2.1). Breach check only in production (calls the HIBP range API).
        \Illuminate\Validation\Rules\Password::defaults(function () {
            $rule = \Illuminate\Validation\Rules\Password::min(10)->letters()->mixedCase()->numbers()->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });

        //
    }
}

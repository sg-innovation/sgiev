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

        // D1: log every email notification in the delivery log (SMS / WhatsApp / push channels log themselves).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSent::class, function ($e) {
            if ($e->channel === 'mail') {
                $key = method_exists($e->notification, 'messageKey') ? $e->notification->messageKey() : class_basename($e->notification);
                $to = $e->notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable ? ($e->notifiable->routes['mail'] ?? null) : ($e->notifiable->email ?? null);
                \App\Models\NotificationDelivery::record($e->notifiable, $key, 'mail', 'sent', ['recipient' => is_array($to) ? implode(', ', array_keys($to)) : $to, 'provider' => config('mail.default')]);
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationFailed::class, function ($e) {
            if ($e->channel === 'mail') {
                $key = method_exists($e->notification, 'messageKey') ? $e->notification->messageKey() : class_basename($e->notification);
                \App\Models\NotificationDelivery::record($e->notifiable, $key, 'mail', 'failed', ['recipient' => $e->notifiable->email ?? null, 'provider' => config('mail.default'), 'error' => (string) ($e->data['exception'] ?? '')]);
            }
        });

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

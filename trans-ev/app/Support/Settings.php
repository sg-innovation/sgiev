<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Platform settings managed in Admin → Settings → Integrations.
 * Values saved there override .env at runtime. Secrets are encrypted with the app key and never returned to the browser.
 */
final class Settings
{
    /**
     * Every manageable setting: key => [config path it overrides (or null), is secret?].
     */
    public const DEFINITIONS = [
        // Email
        'mail.mailer' => ['mail.default', false],
        'mail.from_address' => ['mail.from.address', false],
        'mail.from_name' => ['mail.from.name', false],
        'mail.smtp_host' => ['mail.mailers.smtp.host', false],
        'mail.smtp_port' => ['mail.mailers.smtp.port', false],
        'mail.smtp_username' => ['mail.mailers.smtp.username', false],
        'mail.smtp_password' => ['mail.mailers.smtp.password', true],
        'mail.smtp_scheme' => ['mail.mailers.smtp.scheme', false],
        'mail.ses_key' => ['services.ses.key', true],
        'mail.ses_secret' => ['services.ses.secret', true],
        'mail.ses_region' => ['services.ses.region', false],
        'mail.resend_key' => ['services.resend.key', true],
        // SMS / OTP: provider choice, then MSG91 and SMS Birds
        'sms.provider' => ['services.sms.provider', false],
        'sms.smsbirds_url' => ['services.smsbirds.url', false],
        'sms.smsbirds_username' => ['services.smsbirds.username', false],
        'sms.smsbirds_api_key' => ['services.smsbirds.api_key', true],
        'sms.smsbirds_sender' => ['services.smsbirds.sender', false],
        'sms.smsbirds_route' => ['services.smsbirds.route', false],
        'sms.msg91_auth_key' => ['services.msg91.auth_key', true],
        'sms.msg91_sender_id' => ['services.msg91.sender_id', false],
        'sms.msg91_otp_template_id' => ['services.msg91.otp_template_id', false],
        'sms.msg91_booking_template_id' => ['services.msg91.booking_template_id', false],
        // WhatsApp: provider choice, then Meta Cloud API and SGI Connects
        'whatsapp.provider' => ['services.whatsapp_provider', false],
        'whatsapp.sgi_base_url' => ['services.sgiconnects.base_url', false],
        'whatsapp.sgi_license' => ['services.sgiconnects.license', false],
        'whatsapp.sgi_api_key' => ['services.sgiconnects.api_key', true],
        'whatsapp.access_token' => ['services.whatsapp.token', true],
        'whatsapp.phone_number_id' => ['services.whatsapp.phone_number_id', false],
        'whatsapp.business_account_id' => ['services.whatsapp.business_account_id', false],
        // Support email: replies to ticket emails come back through the provider's inbound webhook
        'support.inbound_address' => ['services.support_inbound.address', false],
        'support.inbound_token' => ['services.support_inbound.token', true],
        // Sign-in: a test number with a fixed code (no SMS), for app-store reviewers and developers
        'auth.test_phone' => ['services.auth_test.phone', false],
        'auth.test_otp' => ['services.auth_test.code', true],
        // OCPP: the address chargers are configured with
        'ocpp.public_url' => ['services.ocpp.public_url', false],
        // Push (Firebase)
        'push.fcm_project_id' => ['services.fcm.project_id', false],
        'push.fcm_service_account' => ['services.fcm.service_account', true],
        // Maps
        'maps.provider' => ['services.maps.provider', false],
        'maps.style_url' => ['services.maps.style', false],
        'maps.google_api_key' => ['services.maps.google_key', true],
        // Payments (platform defaults; each company keeps its own SabPaisa keys)
        'payments.sabpaisa_staging_url' => ['payments.sabpaisa.base_urls.staging', false],
        'payments.sabpaisa_production_url' => ['payments.sabpaisa.base_urls.production', false],
        'payments.default_gateway' => ['payments.default_gateway', false],
    ];

    private const CACHE_KEY = 'system_settings.v1';

    /** Apply saved settings over config. Safe before migrations have run. */
    public static function applyToConfig(): void
    {
        try {
            $values = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('system_settings')) {
                    return [];
                }

                return SystemSetting::query()->get()->mapWithKeys(fn (SystemSetting $s) => [$s->key => [$s->getRawOriginal('value'), $s->is_secret]])->all();
            });
        } catch (Throwable) {
            return; // database not reachable (e.g. during install); keep .env values
        }

        foreach ($values as $key => [$raw, $secret]) {
            $path = self::DEFINITIONS[$key][0] ?? null;
            if ($path === null || $raw === null || $raw === '') {
                continue;
            }
            try {
                config([$path => $secret ? Crypt::decryptString($raw) : $raw]);
            } catch (Throwable) {
                // undecryptable (APP_KEY rotated): ignore and fall back to .env
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $path = self::DEFINITIONS[$key][0] ?? null;

        return $path ? config($path, $default) : $default;
    }

    public static function isSecret(string $key): bool
    {
        return self::DEFINITIONS[$key][1] ?? false;
    }

    /** Whether a secret has a saved value (for the masked "saved" state in the UI). */
    public static function hasSaved(string $key): bool
    {
        return SystemSetting::query()->where('key', $key)->whereNotNull('value')->where('value', '!=', '')->exists();
    }

    /** Current non-secret values for the settings form (secrets are never sent to the browser). */
    public static function formValues(): array
    {
        return collect(self::DEFINITIONS)
            ->reject(fn ($d) => $d[1])
            ->mapWithKeys(fn ($d, $key) => [str_replace('.', '__', $key) => config($d[0])])
            ->all();
    }

    /**
     * Save values. Empty secret fields keep the stored value; "__clear__" removes it.
     *
     * @return list<string> keys that changed
     */
    public static function save(array $values, ?int $userId): array
    {
        $changed = [];
        foreach ($values as $formKey => $value) {
            $key = str_replace('__', '.', $formKey);
            if (! array_key_exists($key, self::DEFINITIONS)) {
                continue;
            }
            $secret = self::isSecret($key);
            if ($secret && ($value === null || $value === '')) {
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            $stored = $secret ? ($value === '__clear__' ? null : Crypt::encryptString((string) $value)) : ($value === null ? null : (string) $value);

            $row = SystemSetting::query()->firstOrNew(['key' => $key]);
            $old = $row->exists ? $row->getRawOriginal('value') : null;
            $same = $secret ? false : $old === $stored;
            if (! $same) {
                $row->fill(['value' => $stored, 'is_secret' => $secret, 'updated_by' => $userId])->save();
                $changed[] = $key;
            }
        }

        Cache::forget(self::CACHE_KEY);
        self::applyToConfig();

        return $changed;
    }
}

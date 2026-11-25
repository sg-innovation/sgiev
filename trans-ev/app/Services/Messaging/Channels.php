<?php

namespace App\Services\Messaging;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Messages;
use App\Support\Settings;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * D1: which channels a templated message goes out on, for one recipient.
 * Email: when the template's email version is on. SMS / WhatsApp / push: when that version exists and is on, the
 * customer has not switched that channel off (WhatsApp needs an explicit opt-in), they have a number or a device, and
 * the provider is configured (in local and test environments messages are simulated and logged instead).
 */
class Channels
{
    public const PREF_DEFAULTS = ['sms' => true, 'whatsapp' => false, 'push' => true];

    public static function prefs(?User $user): array
    {
        return array_merge(self::PREF_DEFAULTS, array_intersect_key((array) ($user?->notification_prefs ?? []), self::PREF_DEFAULTS));
    }

    /** @return list<string> */
    public static function for(object $notifiable, string $key): array
    {
        $channels = [];
        if (Messages::enabled($key, 'mail')) {
            $channels[] = 'mail';
        }
        if ($notifiable instanceof AnonymousNotifiable || ! $notifiable instanceof User) {
            return $channels;
        }
        $prefs = self::prefs($notifiable);
        if ($prefs['sms'] && Messages::enabled($key, 'sms') && filled($notifiable->phone) && self::ready('sms')) {
            $channels[] = SmsChannel::class;
        }
        if ($prefs['whatsapp'] && Messages::enabled($key, 'whatsapp') && filled($notifiable->phone) && self::ready('whatsapp')) {
            $channels[] = WhatsAppChannel::class;
        }
        if ($prefs['push'] && Messages::enabled($key, 'push') && self::ready('push') && DeviceToken::query()->where('user_id', $notifiable->id)->exists()) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    /** Provider configured, or simulated in local/testing. */
    public static function ready(string $channel): bool
    {
        return self::configured($channel) || app()->environment('local', 'testing');
    }

    public static function configured(string $channel): bool
    {
        return match ($channel) {
            'sms' => self::provider('sms') === 'smsbirds'
                ? filled(Settings::get('sms.smsbirds_url')) && filled(Settings::get('sms.smsbirds_username')) && filled(Settings::get('sms.smsbirds_api_key')) && filled(Settings::get('sms.smsbirds_sender'))
                : filled(Settings::get('sms.msg91_auth_key')) && filled(Settings::get('sms.msg91_sender_id')),
            'whatsapp' => self::provider('whatsapp') === 'sgiconnects'
                ? filled(Settings::get('whatsapp.sgi_base_url')) && filled(Settings::get('whatsapp.sgi_license')) && filled(Settings::get('whatsapp.sgi_api_key'))
                : filled(Settings::get('whatsapp.access_token')) && filled(Settings::get('whatsapp.phone_number_id')),
            'push' => filled(Settings::get('push.fcm_project_id')) && filled(Settings::get('push.fcm_service_account')),
            default => false,
        };
    }

    /** Providers each channel can use (chosen in Settings → Integrations). */
    public const PROVIDERS = [
        'sms' => ['msg91' => 'MSG91', 'smsbirds' => 'SMS Birds'],
        'whatsapp' => ['meta' => 'Meta WhatsApp Cloud API', 'sgiconnects' => 'SGI Connects'],
    ];

    /** Selected provider for a channel (falls back to the first one). */
    public static function provider(string $channel): string
    {
        $options = self::PROVIDERS[$channel] ?? [];
        $chosen = (string) Settings::get($channel.'.provider');

        return array_key_exists($chosen, $options) ? $chosen : (string) array_key_first($options);
    }

    /** +91 E.164 digits for an Indian mobile. */
    public static function msisdn(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (strlen($d) === 10) {
            return '91'.$d;
        }

        return strlen($d) === 12 && str_starts_with($d, '91') ? $d : null;
    }
}

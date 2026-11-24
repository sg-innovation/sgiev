<?php

namespace App\Services\Messaging;

use App\Models\NotificationDelivery;
use App\Support\Settings;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through Meta's Cloud API, using approved message templates. In Message templates the WhatsApp version's
 * "Template name" is the approved Meta template; its body's {{variables}}, in order, fill the template's {{1}}, {{2}}…
 * Only customers who opted in to WhatsApp receive these.
 */
class WhatsAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{key: string, text: ?string, template_name: ?string, params: list<string>, language: string} $m */
        $m = $notification->toWhatsApp($notifiable);
        $to = Channels::msisdn($notifiable->phone ?? null);
        if (! $m['text'] || ! $to) {
            NotificationDelivery::record($notifiable, $m['key'], 'whatsapp', 'skipped', ['error' => $to ? 'No WhatsApp text' : 'No valid mobile number']);

            return;
        }
        if (! Channels::configured('whatsapp')) {
            NotificationDelivery::record($notifiable, $m['key'], 'whatsapp', 'simulated', ['recipient' => $to, 'provider' => 'log', 'preview' => $m['text']]);

            return;
        }
        if (blank($m['template_name'])) {
            NotificationDelivery::record($notifiable, $m['key'], 'whatsapp', 'failed', ['recipient' => $to, 'provider' => 'meta', 'preview' => $m['text'], 'error' => 'No approved template name set for this message']);

            return;
        }
        try {
            $res = Http::timeout(10)->withToken((string) Settings::get('whatsapp.access_token'))
                ->post('https://graph.facebook.com/v20.0/'.Settings::get('whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
                    'template' => [
                        'name' => $m['template_name'], 'language' => ['code' => $m['language']],
                        'components' => $m['params'] ? [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => mb_substr($p, 0, 200)], $m['params'])]] : [],
                    ],
                ]);
            NotificationDelivery::record($notifiable, $m['key'], 'whatsapp', $res->successful() ? 'sent' : 'failed', [
                'recipient' => $to, 'provider' => 'meta', 'id' => $res->json('messages.0.id'), 'preview' => $m['text'],
                'error' => $res->successful() ? null : ($res->json('error.message') ?? 'HTTP '.$res->status()),
            ]);
        } catch (\Throwable $e) {
            NotificationDelivery::record($notifiable, $m['key'], 'whatsapp', 'failed', ['recipient' => $to, 'provider' => 'meta', 'preview' => $m['text'], 'error' => $e->getMessage()]);
        }
    }
}

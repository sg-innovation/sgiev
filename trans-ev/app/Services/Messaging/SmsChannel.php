<?php

namespace App\Services\Messaging;

use App\Models\NotificationDelivery;
use App\Support\Settings;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * SMS through MSG91 (Flow API). India requires a DLT-registered template: the template's "DLT template ID" in Message
 * templates is the MSG91 flow/template ID, and the flow's ##variables## use the same names as the {{variables}} here.
 */
class SmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{key: string, text: ?string, vars: array, template_id: ?string} $m */
        $m = $notification->toSms($notifiable);
        $to = Channels::msisdn($notifiable->phone ?? null);
        if (! $m['text'] || ! $to) {
            NotificationDelivery::record($notifiable, $m['key'], 'sms', 'skipped', ['error' => $to ? 'No SMS text' : 'No valid mobile number']);

            return;
        }
        if (! Channels::configured('sms')) {
            NotificationDelivery::record($notifiable, $m['key'], 'sms', 'simulated', ['recipient' => $to, 'provider' => 'log', 'preview' => $m['text']]);

            return;
        }
        if (blank($m['template_id'])) {
            NotificationDelivery::record($notifiable, $m['key'], 'sms', 'failed', ['recipient' => $to, 'provider' => 'msg91', 'preview' => $m['text'], 'error' => 'No DLT template ID set for this message']);

            return;
        }
        try {
            $res = Http::timeout(10)->withHeaders(['authkey' => (string) Settings::get('sms.msg91_auth_key'), 'accept' => 'application/json'])
                ->post('https://control.msg91.com/api/v5/flow', [
                    'template_id' => $m['template_id'], 'short_url' => '0', 'sender' => (string) Settings::get('sms.msg91_sender_id'),
                    'recipients' => [['mobiles' => $to] + collect($m['vars'])->map(fn ($v) => mb_substr(strip_tags((string) $v), 0, 30))->all()],
                ]);
            $ok = $res->successful() && ($res->json('type') ?? 'success') !== 'error';
            NotificationDelivery::record($notifiable, $m['key'], 'sms', $ok ? 'sent' : 'failed', [
                'recipient' => $to, 'provider' => 'msg91', 'id' => $res->json('message'), 'preview' => $m['text'],
                'error' => $ok ? null : ($res->json('message') ?? 'HTTP '.$res->status()),
            ]);
        } catch (\Throwable $e) {
            NotificationDelivery::record($notifiable, $m['key'], 'sms', 'failed', ['recipient' => $to, 'provider' => 'msg91', 'preview' => $m['text'], 'error' => $e->getMessage()]);
        }
    }
}

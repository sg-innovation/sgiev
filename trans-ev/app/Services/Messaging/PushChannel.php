<?php

namespace App\Services\Messaging;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Support\Settings;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Push to the customer's mobile apps through Firebase Cloud Messaging (HTTP v1). The service-account JSON from
 * Integrations signs a short-lived OAuth token (cached). Tokens Firebase reports as unregistered are removed.
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{key: string, title: ?string, body: ?string, url: ?string} $m */
        $m = $notification->toPush($notifiable);
        $tokens = DeviceToken::query()->where('user_id', $notifiable->id)->pluck('token', 'id');
        if (! $m['body'] || $tokens->isEmpty()) {
            NotificationDelivery::record($notifiable, $m['key'], 'push', 'skipped', ['error' => $tokens->isEmpty() ? 'No app installed' : 'No push text']);

            return;
        }
        if (! Channels::configured('push')) {
            NotificationDelivery::record($notifiable, $m['key'], 'push', 'simulated', ['recipient' => $tokens->count().' device(s)', 'provider' => 'log', 'preview' => $m['title'].': '.$m['body']]);

            return;
        }
        try {
            $token = $this->accessToken();
            $project = (string) Settings::get('push.fcm_project_id');
            $sent = 0;
            $errors = [];
            foreach ($tokens as $id => $device) {
                $res = Http::timeout(10)->withToken($token)->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => [
                    'token' => $device, 'notification' => ['title' => $m['title'], 'body' => $m['body']], 'data' => array_filter(['url' => $m['url'], 'template' => $m['key']]),
                ]]);
                if ($res->successful()) {
                    $sent++;
                } else {
                    $errors[] = $res->json('error.status') ?? 'HTTP '.$res->status();
                    if (in_array($res->json('error.status'), ['NOT_FOUND', 'UNREGISTERED'], true) || $res->status() === 404) {
                        DeviceToken::query()->whereKey($id)->delete();
                    }
                }
            }
            NotificationDelivery::record($notifiable, $m['key'], 'push', $sent > 0 ? 'sent' : 'failed', [
                'recipient' => $tokens->count().' device(s)', 'provider' => 'fcm', 'preview' => $m['title'].': '.$m['body'], 'error' => $errors ? implode(', ', array_unique($errors)) : null,
            ]);
        } catch (\Throwable $e) {
            NotificationDelivery::record($notifiable, $m['key'], 'push', 'failed', ['recipient' => $tokens->count().' device(s)', 'provider' => 'fcm', 'error' => $e->getMessage()]);
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', 3000, function () {
            $sa = json_decode((string) Settings::get('push.fcm_service_account'), true) ?: throw new \RuntimeException('Service account JSON is not valid.');
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = time();
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            openssl_sign("{$head}.{$claims}", $sig, $sa['private_key'], 'sha256WithRSAEncryption') ?: throw new \RuntimeException('Could not sign the Firebase token.');
            $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "{$head}.{$claims}.".$b64($sig),
            ]);

            return $res->json('access_token') ?? throw new \RuntimeException('Firebase rejected the service account: '.($res->json('error_description') ?? $res->status()));
        });
    }
}

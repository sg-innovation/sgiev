<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message sent (or skipped / failed) on one channel. */
class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    public const CHANNELS = ['mail' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'push' => 'Push'];

    public const STATUSES = ['sent' => 'Sent', 'simulated' => 'Simulated (test mode)', 'skipped' => 'Skipped', 'failed' => 'Failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Write a log row; never breaks the caller. */
    public static function record(?object $notifiable, string $template, string $channel, string $status, array $extra = []): void
    {
        try {
            static::create([
                'user_id' => $notifiable instanceof User ? $notifiable->id : null, 'template' => mb_substr($template, 0, 80), 'channel' => $channel, 'status' => $status,
                'recipient' => isset($extra['recipient']) ? mb_substr((string) $extra['recipient'], 0, 255) : null,
                'provider' => $extra['provider'] ?? null, 'provider_message_id' => isset($extra['id']) ? mb_substr((string) $extra['id'], 0, 120) : null,
                'preview' => isset($extra['preview']) ? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $extra['preview']))), 0, 255) : null,
                'error' => isset($extra['error']) ? mb_substr((string) $extra['error'], 0, 500) : null,
            ]);
        } catch (\Throwable) {
            // logging must never stop a message
        }
    }
}

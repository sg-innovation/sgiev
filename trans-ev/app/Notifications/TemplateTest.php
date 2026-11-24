<?php

namespace App\Notifications;

use App\Notifications\Concerns\TemplatedMessage;
use App\Services\Messaging\PushChannel;
use App\Services\Messaging\SmsChannel;
use App\Services\Messaging\WhatsAppChannel;
use Illuminate\Notifications\Notification;

/** "Send test" from Message templates: one channel, sample values, to the admin who clicked it. */
class TemplateTest extends Notification
{
    use TemplatedMessage {
        via as protected templatedVia;
    }

    public function __construct(public string $key, public string $channel, public array $sample) {}

    public function messageKey(): string
    {
        return $this->key;
    }

    public function messageVars(object $notifiable): array
    {
        return ['name' => $notifiable->name ?? ''] + $this->sample;
    }

    public function messageUrl(object $notifiable): ?string
    {
        return url('/');
    }

    public function via(object $notifiable): array
    {
        return [match ($this->channel) { 'sms' => SmsChannel::class, 'whatsapp' => WhatsAppChannel::class, 'push' => PushChannel::class, default => 'mail' }];
    }
}

<?php

namespace App\Notifications\Concerns;

use App\Services\Messaging\Channels;
use App\Support\Messages;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * D1: a notification whose wording comes from Message templates and which goes out on every channel the template,
 * the customer's preferences and the configured providers allow (email, SMS, WhatsApp, push).
 */
trait TemplatedMessage
{
    abstract public function messageKey(): string;

    /** Variables for {{placeholders}}. */
    abstract public function messageVars(object $notifiable): array;

    public function messageUrl(object $notifiable): ?string
    {
        return null;
    }

    public function via(object $notifiable): array
    {
        return Channels::for($notifiable, $this->messageKey());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return Messages::mail($this->messageKey(), $this->messageVars($notifiable), $this->messageUrl($notifiable));
    }

    public function toSms(object $notifiable): array
    {
        $vars = $this->messageVars($notifiable);

        return ['key' => $this->messageKey(), 'text' => Messages::sms($this->messageKey(), $vars), 'vars' => $vars,
            'template_id' => Messages::template($this->messageKey(), 'sms')['dlt_template_id'] ?? null];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $vars = $this->messageVars($notifiable);
        $t = Messages::template($this->messageKey(), 'whatsapp');
        $body = (string) ($t['body'] ?? '');
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $body, $m);

        return [
            'key' => $this->messageKey(), 'text' => $body !== '' ? Messages::fill($body, $vars, plain: true) : null,
            'template_name' => $t['dlt_template_id'] ?? null, 'language' => 'en',
            'params' => array_map(fn ($v) => strip_tags((string) ($vars[$v] ?? '')), $m[1]),
        ];
    }

    public function toPush(object $notifiable): array
    {
        $vars = $this->messageVars($notifiable);
        $t = Messages::template($this->messageKey(), 'push');

        return [
            'key' => $this->messageKey(), 'title' => filled($t['subject'] ?? null) ? Messages::fill($t['subject'], $vars, plain: true) : config('app.name'),
            'body' => filled($t['body'] ?? null) ? Messages::fill($t['body'], $vars, plain: true) : null, 'url' => $this->messageUrl($notifiable),
        ];
    }
}

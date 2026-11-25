<?php

namespace App\Notifications;

use App\Support\Messages;
use Illuminate\Notifications\Notification;

/** The SMS with a one-time login code. The code itself is never stored in the notification log. */
class OtpMessage extends Notification
{
    public function __construct(private readonly string $code, private readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return [];
    }

    public function toSms(object $notifiable): array
    {
        $vars = ['code' => $this->code, 'minutes' => (string) $this->minutes];

        return ['key' => 'auth.otp', 'text' => Messages::sms('auth.otp', $vars), 'vars' => $vars,
            'template_id' => Messages::template('auth.otp', 'sms')['dlt_template_id'] ?? null, 'sensitive' => true];
    }
}

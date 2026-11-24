<?php

namespace App\Notifications;

use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Partner onboarding / approval updates to company owners (wording from Message templates). */
class PartnerUpdate extends Notification
{
    use Queueable;

    public function __construct(public string $template, public array $vars = []) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled($this->template) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return Messages::mail($this->template, ['name' => $notifiable->name ?? ''] + $this->vars, url('/admin'));
    }
}

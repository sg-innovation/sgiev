<?php

namespace App\Notifications;

use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Partner onboarding / approval / wallet / reconciliation updates to staff (wording from Message templates). */
class PartnerUpdate extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public string $template, public array $vars = []) {}

    public function messageKey(): string
    {
        return $this->template;
    }

    public function messageVars(object $notifiable): array
    {
        return ['name' => $notifiable->name ?? ''] + $this->vars;
    }

    public function messageUrl(object $notifiable): ?string
    {
        return url('/admin');
    }
}

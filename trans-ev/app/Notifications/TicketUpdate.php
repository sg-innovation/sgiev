<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email about a support ticket (wording from Message templates). Never includes internal notes. */
class TicketUpdate extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public string $template, public array $vars = []) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled($this->template) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isCustomer = $notifiable->id === $this->ticket->requester_id && ! $notifiable->hasAnyRole(\App\Enums\Role::staff());
        $url = $isCustomer
            ? route('support.show', $this->ticket)
            : \App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $this->ticket], panel: 'admin');

        return Messages::mail($this->template, [
            'name' => $notifiable->name ?? '',
            'number' => $this->ticket->number,
            'subject' => $this->ticket->subject,
            'status' => Ticket::STATUSES[$this->ticket->status] ?? $this->ticket->status,
            'requester' => $this->ticket->requester?->name ?? '',
        ] + $this->vars, $url);
    }
}

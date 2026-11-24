<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Support ticket updates (wording from Message templates). Never includes internal notes. */
class TicketUpdate extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public Ticket $ticket, public string $template, public array $vars = []) {}

    public function messageKey(): string
    {
        return $this->template;
    }

    public function messageVars(object $notifiable): array
    {
        return [
            'name' => $notifiable->name ?? '',
            'number' => $this->ticket->number,
            'subject' => $this->ticket->subject,
            'status' => Ticket::STATUSES[$this->ticket->status] ?? $this->ticket->status,
            'requester' => $this->ticket->requester?->name ?? '',
        ] + $this->vars;
    }

    public function messageUrl(object $notifiable): ?string
    {
        $isCustomer = $notifiable->id === $this->ticket->requester_id && ! $notifiable->hasAnyRole(\App\Enums\Role::staff());

        return $isCustomer
            ? route('support.show', $this->ticket)
            : \App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $this->ticket], panel: 'admin');
    }
}

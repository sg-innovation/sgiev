<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use App\Services\Support\InboundEmailService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Support ticket updates (wording from Message templates). Never includes internal notes. */
class TicketUpdate extends Notification
{
    use Queueable, TemplatedMessage {
        toMail as protected templatedMail;
    }

    public function __construct(public Ticket $ticket, public string $template, public array $vars = []) {}

    public function messageKey(): string
    {
        return $this->template;
    }

    public function messageVars(object $notifiable): array
    {
        return [
            'name' => $notifiable->name ?? ($notifiable instanceof AnonymousNotifiable ? (string) $this->ticket->guest_name : ''),
            'number' => $this->ticket->number,
            'subject' => $this->ticket->subject,
            'status' => Ticket::STATUSES[$this->ticket->status] ?? $this->ticket->status,
            'requester' => $this->ticket->requesterName(),
        ] + $this->vars;
    }

    /** Ticket emails can be answered by email: replies to this address land in the thread. */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->templatedMail($notifiable);
        if ($reply = InboundEmailService::replyAddress($this->ticket)) {
            $mail->replyTo($reply, 'Trans EV support');
        }

        return $mail;
    }

    public function messageUrl(object $notifiable): ?string
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return $this->ticket->guestUrl();
        }
        $isCustomer = $notifiable->id === $this->ticket->requester_id && ! $notifiable->hasAnyRole(\App\Enums\Role::staff());

        return $isCustomer
            ? route('support.show', $this->ticket)
            : \App\Filament\Resources\Tickets\TicketResource::getUrl('view', ['record' => $this->ticket], panel: 'admin');
    }
}

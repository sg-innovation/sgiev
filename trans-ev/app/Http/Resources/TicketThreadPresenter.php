<?php

namespace App\Http\Resources;

use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Support\InboundEmailService;
use App\Services\TicketService;

/** The public thread of a ticket for the requester (account or guest). Internal notes are never included. */
final class TicketThreadPresenter
{
    /** @param  callable(TicketMessage, int): string  $fileUrl */
    public static function present(Ticket $ticket, callable $fileUrl, ?string $bookingHref = null): array
    {
        $ticket->loadMissing(['category', 'priority', 'booking.station', 'publicMessages.user']);
        $tz = Station::TIMEZONE;

        return \App\Http\Controllers\SupportController::summary($ticket) + [
            'booking' => $ticket->booking ? ['code' => $ticket->booking->code, 'station' => $ticket->booking->station?->name, 'href' => $bookingHref] : null,
            'canReply' => $ticket->status !== 'closed',
            'canResolve' => $ticket->isActive(),
            'canReopen' => $ticket->status === 'resolved',
            'messages' => $ticket->publicMessages->map(function (TicketMessage $m) use ($ticket, $tz, $fileUrl) {
                $mine = $ticket->isFromRequester($m);

                return [
                    'id' => $m->id,
                    'mine' => $mine,
                    'author' => $mine ? 'You' : ($m->user?->name ? $m->user->name.' · Support' : 'Trans EV support'),
                    'body' => $m->body,
                    'at' => $m->created_at->setTimezone($tz)->format('j M Y, g:i A'),
                    'viaEmail' => $m->via === 'email',
                    'files' => collect($m->attachments ?? [])->map(fn ($f, $i) => ['name' => $f['name'], 'url' => $fileUrl($m, $i)])->values(),
                ];
            })->values(),
        ];
    }

    public static function limits(): array
    {
        return ['files' => TicketService::maxFiles(), 'kb' => TicketService::maxKb()];
    }

    public static function replyByEmail(): bool
    {
        return InboundEmailService::enabled();
    }
}

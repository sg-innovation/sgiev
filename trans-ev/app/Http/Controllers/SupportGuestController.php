<?php

namespace App\Http\Controllers;

use App\Http\Resources\TicketThreadPresenter;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest support tickets (contact form without an account). Every route is a signed URL from the guest's email;
 * the link carries the ticket's link version, so staff can cancel old links by issuing a new one.
 */
class SupportGuestController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    private function guard(Request $request, Ticket $ticket): void
    {
        abort_unless($ticket->isGuest() && (int) $request->query('v') === (int) $ticket->guest_link_version, 404);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->guard($request, $ticket);

        return Inertia::render('support/guest', [
            'ticket' => TicketThreadPresenter::present($ticket, fn (TicketMessage $m, int $i) => $ticket->guestUrl('support.guest.file', ['message' => $m->id, 'index' => $i])),
            'limits' => TicketThreadPresenter::limits(),
            'urls' => ['reply' => $ticket->guestUrl('support.guest.reply'), 'status' => $ticket->guestUrl('support.guest.status')],
            'guestName' => (string) $ticket->guest_name,
            'replyByEmail' => TicketThreadPresenter::replyByEmail(),
        ])->withViewData(['noindex' => true]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guard($request, $ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.TicketService::maxFiles()],
            'files.*' => [File::types(TicketService::MIMES)->max(TicketService::maxKb())],
        ]);
        $this->tickets->guestReply($ticket, $data['body'], $request->file('files', []));

        return redirect($ticket->guestUrl())->with('success', 'Reply sent. We will email you when support answers.');
    }

    public function status(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->guard($request, $ticket);
        $data = $request->validate(['status' => ['required', Rule::in(['resolved', 'closed', 'open'])], 'rating' => ['nullable', 'integer', 'between:1,5']]);
        $this->tickets->guestStatus($ticket, $data['status'], isset($data['rating']) ? (int) $data['rating'] : null);

        return redirect($ticket->guestUrl())->with('success', match ($data['status']) {
            'open' => 'Ticket reopened.', 'closed' => 'Thank you for your feedback.', default => 'Glad we could help. Reply any time to reopen it.',
        });
    }

    public function file(Request $request, Ticket $ticket, TicketMessage $message, int $index): \Symfony\Component\HttpFoundation\Response
    {
        $this->guard($request, $ticket);
        abort_unless($message->ticket_id === $ticket->id && ! $message->is_internal, 404);

        return TicketService::attachmentStream($message->attachments[$index] ?? abort(404));
    }
}

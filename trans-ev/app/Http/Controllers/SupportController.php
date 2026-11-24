<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\TicketPriority;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

/** My account → Support: raise and follow tickets (any signed-in user). */
class SupportController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = Station::TIMEZONE;

        return Inertia::render('support/index', [
            'tickets' => Ticket::query()->where('requester_id', $user->id)->with(['category', 'priority'])->latest('last_activity_at')->paginate(15)
                ->through(fn (Ticket $t) => self::summary($t)),
            'categories' => TicketCategory::query()->for($user)->get()->map(fn (TicketCategory $c) => [
                'id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'requiresBooking' => $c->requires_booking,
                'defaultPriorityId' => $c->default_priority_id,
            ]),
            'priorities' => TicketPriority::query()->where('customer_selectable', true)->orderBy('sort')->get()->map(fn (TicketPriority $p) => [
                'id' => $p->id, 'name' => $p->name, 'response' => TicketPriority::humanMinutes($p->first_response_minutes),
            ]),
            'bookings' => $user->bookings()->with('station')->latest('start_at')->limit(20)->get()->map(fn ($b) => [
                'id' => $b->id, 'label' => "{$b->code} · {$b->station->name} · ".$b->start_at->setTimezone($tz)->format('j M Y'),
            ]),
            'preselectBooking' => filled($request->query('booking')) ? $user->bookings()->where('code', (string) $request->query('booking'))->value('id') : null,
            'limits' => ['files' => TicketService::maxFiles(), 'kb' => TicketService::maxKb()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('ticket_categories', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', 'integer', Rule::exists('ticket_priorities', 'id')],
            'booking_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.TicketService::maxFiles()],
            'files.*' => [File::types(TicketService::MIMES)->max(TicketService::maxKb())],
        ]);
        $booking = filled($data['booking_id'] ?? null) ? $request->user()->bookings()->findOrFail($data['booking_id']) : null;

        $ticket = $this->tickets->open(
            $request->user(), TicketCategory::findOrFail($data['category_id']), $data['subject'], $data['body'],
            filled($data['priority_id'] ?? null) ? TicketPriority::find($data['priority_id']) : null, $booking, $request->file('files', []),
        );

        return to_route('support.show', $ticket)->with('success', "Ticket {$ticket->number} created. We’ll reply by email.");
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        abort_unless($ticket->requester_id === $request->user()->id, 404);
        $ticket->load(['category', 'priority', 'booking.station', 'assignee', 'publicMessages.user']);
        $tz = Station::TIMEZONE;

        return Inertia::render('support/show', [
            'ticket' => self::summary($ticket) + [
                'booking' => $ticket->booking ? ['code' => $ticket->booking->code, 'station' => $ticket->booking->station?->name] : null,
                'canReply' => $ticket->status !== 'closed',
                'canResolve' => $ticket->isActive(),
                'canReopen' => $ticket->status === 'resolved',
                'messages' => $ticket->publicMessages->map(fn (TicketMessage $m) => [
                    'id' => $m->id,
                    'mine' => $m->user_id === $ticket->requester_id,
                    'author' => $m->user_id === $ticket->requester_id ? 'You' : ($m->user?->name ? $m->user->name.' · Support' : 'Trans EV support'),
                    'body' => $m->body,
                    'at' => $m->created_at->setTimezone($tz)->format('j M Y, g:i A'),
                    'files' => collect($m->attachments ?? [])->map(fn ($f, $i) => ['name' => $f['name'], 'url' => route('support.file', [$ticket, $m->id, $i])])->values(),
                ]),
            ],
            'limits' => ['files' => TicketService::maxFiles(), 'kb' => TicketService::maxKb()],
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->requester_id === $request->user()->id, 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.TicketService::maxFiles()],
            'files.*' => [File::types(TicketService::MIMES)->max(TicketService::maxKb())],
        ]);
        $this->tickets->reply($ticket, $request->user(), $data['body'], false, $request->file('files', []));

        return back()->with('success', 'Reply sent.');
    }

    public function status(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->requester_id === $request->user()->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['resolved', 'closed', 'open'])], 'rating' => ['nullable', 'integer', 'between:1,5']]);
        $this->tickets->setStatus($ticket, $data['status'], $request->user());
        if (filled($data['rating'] ?? null)) {
            $ticket->forceFill(['rating' => $data['rating']])->save();
        }

        return back()->with('success', $data['status'] === 'open' ? 'Ticket reopened.' : 'Thanks! Ticket marked as '.strtolower(Ticket::STATUSES[$data['status']]).'.');
    }

    /** Attachment download: requester (public messages only) or staff who can view the ticket. */
    public function file(Request $request, Ticket $ticket, TicketMessage $message, int $index): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($message->ticket_id === $ticket->id, 404);
        $user = $request->user();
        $allowed = $this->tickets->canView($user, $ticket) && (! $message->is_internal || $this->tickets->canWork($user, $ticket));
        abort_unless($allowed, 404);
        $file = $message->attachments[$index] ?? abort(404);

        return TicketService::attachmentStream($file);
    }

    public static function summary(Ticket $t): array
    {
        $tz = Station::TIMEZONE;

        return [
            'uuid' => $t->uuid, 'number' => $t->number, 'subject' => $t->subject,
            'status' => $t->status, 'statusLabel' => Ticket::STATUSES[$t->status] ?? $t->status,
            'category' => $t->category?->name, 'priority' => $t->priority?->name, 'priorityColor' => $t->priority?->color,
            'createdAt' => $t->created_at->setTimezone($tz)->format('j M Y'),
            'updatedAt' => ($t->last_activity_at ?? $t->updated_at)->setTimezone($tz)->format('j M Y, g:i A'),
            'awaitingYou' => $t->status === 'waiting_on_customer',
            'rating' => $t->rating,
        ];
    }
}

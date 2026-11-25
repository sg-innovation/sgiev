<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupportController as WebSupportController;
use App\Http\Resources\TicketThreadPresenter;
use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\TicketPriority;
use App\Models\WaitlistEntry;
use App\Services\GstDocumentPdf;
use App\Services\TicketService;
use App\Services\WaitlistService;
use App\Support\IndianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\Response;

/** Support tickets (threaded, with attachments), the waiting list and GST document downloads for the app. */
class SupportController extends Controller
{
    public function __construct(private readonly TicketService $tickets, private readonly WaitlistService $waitlist) {}

    // ── Tickets ─────────────────────────────────────────────────────────────────────────────────────────────

    public function ticketOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => TicketCategory::query()->for($request->user())->get()->map(fn (TicketCategory $c) => ['id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'requires_booking' => (bool) $c->requires_booking]),
            'priorities' => TicketPriority::query()->where('customer_selectable', true)->orderBy('sort')->get()->map(fn (TicketPriority $p) => ['id' => $p->id, 'name' => $p->name, 'first_reply' => TicketPriority::humanMinutes($p->first_response_minutes)]),
            'limits' => TicketThreadPresenter::limits(),
        ]]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $page = Ticket::query()->where('requester_id', $request->user()->id)->with(['category', 'priority'])->latest('last_activity_at')->paginate(20);

        return response()->json(['data' => collect($page->items())->map(fn (Ticket $t) => WebSupportController::summary($t))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function storeTicket(Request $request): JsonResponse
    {
        $d = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('ticket_categories', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', 'integer', Rule::exists('ticket_priorities', 'id')],
            'booking_code' => ['nullable', 'string', 'max:20'],
            'subject' => ['required', 'string', 'min:5', 'max:160'], 'body' => ['required', 'string', 'min:10', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.TicketService::maxFiles()], 'files.*' => [File::types(TicketService::MIMES)->max(TicketService::maxKb())],
        ]);
        $booking = filled($d['booking_code'] ?? null) ? $request->user()->bookings()->where('code', strtoupper($d['booking_code']))->firstOrFail() : null;
        $t = $this->tickets->open($request->user(), TicketCategory::findOrFail($d['category_id']), $d['subject'], $d['body'],
            filled($d['priority_id'] ?? null) ? TicketPriority::find($d['priority_id']) : null, $booking, $request->file('files', []));

        return response()->json(['data' => $this->thread($t)], 201);
    }

    public function ticket(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->requester_id === $request->user()->id, 404);

        return response()->json(['data' => $this->thread($ticket)]);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->requester_id === $request->user()->id, 404);
        $d = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000'], 'files' => ['nullable', 'array', 'max:'.TicketService::maxFiles()],
            'files.*' => [File::types(TicketService::MIMES)->max(TicketService::maxKb())]]);
        $this->tickets->reply($ticket, $request->user(), $d['body'], false, $request->file('files', []), null, 'app');

        return response()->json(['data' => $this->thread($ticket->fresh())], 201);
    }

    public function ticketStatus(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->requester_id === $request->user()->id, 404);
        $d = $request->validate(['status' => ['required', Rule::in(['resolved', 'closed', 'open'])], 'rating' => ['nullable', 'integer', 'between:1,5']]);
        $this->tickets->setStatus($ticket, $d['status'], $request->user());
        if (filled($d['rating'] ?? null)) {
            $ticket->forceFill(['rating' => $d['rating']])->save();
        }

        return response()->json(['data' => $this->thread($ticket->fresh())]);
    }

    private function thread(Ticket $t): array
    {
        return TicketThreadPresenter::present($t, fn (TicketMessage $m, int $i) => URL::temporarySignedRoute('api.v1.documents.ticket-file', now()->addMinutes(30), ['ticket' => $t->uuid, 'message' => $m->id, 'index' => $i]));
    }

    // ── Waiting list ────────────────────────────────────────────────────────────────────────────────────────

    public function waitlist(Request $request): JsonResponse
    {
        $tz = Station::TIMEZONE;

        return response()->json(['data' => WaitlistEntry::query()->where('user_id', $request->user()->id)->with(['station', 'booking'])->latest('id')->limit(30)->get()
            ->map(fn (WaitlistEntry $e) => [
                'uuid' => $e->uuid, 'status' => $e->status, 'status_label' => WaitlistEntry::STATUSES[$e->status] ?? $e->status,
                'station' => ['name' => $e->station->name, 'slug' => $e->station->slug, 'city' => $e->station->city],
                'date' => $e->date->toDateString(), 'window_from' => substr((string) $e->window_from, 0, 5), 'window_to' => substr((string) $e->window_to, 0, 5), 'duration' => $e->duration,
                'position' => $this->waitlist->position($e), 'estimated_at' => $e->status === 'waiting' ? $this->waitlist->estimate($e)?->toIso8601String() : null,
                'offer' => $e->status === 'offered' && $e->booking ? ['booking_code' => $e->booking->code, 'start_at' => $e->booking->start_at->toIso8601String(),
                    'expires_at' => $e->offer_expires_at?->toIso8601String(), 'amount' => (float) $e->booking->total_amount] : null,
                'booking_code' => $e->status === 'booked' ? $e->booking?->code : null,
            ])->values()]);
    }

    public function joinWaitlist(Request $request, Station $station): JsonResponse
    {
        abort_unless($station->is_published && $station->approval_status === 'approved' && $station->company?->isActive(), 404);
        $request->merge([
            'registration_no' => strtoupper(preg_replace('/[\s-]+/', '', (string) $request->input('registration_no'))),
            'phone' => preg_replace('/\D/', '', (string) $request->input('phone')),
            'gstin' => $request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null,
        ]);
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'window_from' => ['required', 'date_format:H:i'], 'window_to' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer'], 'connector' => ['required', Rule::enum(\App\Enums\ConnectorType::class)],
            'make' => ['required', 'string', 'max:60'], 'model' => ['required', 'string', 'max:80'],
            'registration_no' => ['required', 'string', 'regex:/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$|^[0-9]{2}BH[0-9]{4}[A-Z]{1,2}$/'],
            'battery_kwh' => ['required', 'decimal:0,2', 'between:1,200'], 'battery_from' => ['required', 'integer', 'between:0,99'], 'battery_to' => ['required', 'integer', 'between:1,100', 'gt:battery_from'],
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'gstin' => ['nullable', new \App\Rules\ValidGstin], 'state_code' => ['required', Rule::in(array_keys(IndianStates::STATES))], 'accept_rules' => ['accepted'],
        ]);
        $entry = $this->waitlist->join($request->user(), $station, $d);

        return response()->json(['data' => ['uuid' => $entry->uuid, 'status' => $entry->status, 'position' => $this->waitlist->position($entry)]], 201);
    }

    public function leaveWaitlist(Request $request, WaitlistEntry $entry): JsonResponse
    {
        $this->waitlist->leave($entry, $request->user());

        return response()->json(['message' => 'You have left the waiting list.']);
    }

    // ── Signed downloads (links from the booking and ticket responses, valid 30 minutes) ─────────────────────

    public function invoicePdf(Booking $booking, GstDocumentPdf $pdf): Response
    {
        $invoice = $booking->invoice ?? abort(404);

        return $this->pdf($pdf->render($invoice), $invoice->filename());
    }

    public function creditNotePdf(CreditNote $creditNote, GstDocumentPdf $pdf): Response
    {
        return $this->pdf($pdf->render($creditNote), $creditNote->filename());
    }

    public function ticketFile(Ticket $ticket, TicketMessage $message, int $index): Response
    {
        abort_unless($message->ticket_id === $ticket->id && ! $message->is_internal, 404);

        return TicketService::attachmentStream($message->attachments[$index] ?? abort(404));
    }

    private function pdf(string $bytes, string $name): Response
    {
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

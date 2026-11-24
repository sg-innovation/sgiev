<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\TicketPriority;
use App\Models\User;
use App\Notifications\TicketUpdate;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets: routing, SLA dates, replies, internal notes, assignment and status changes.
 * Who may act is decided by permissions (tickets.view / reply / assign / manage) within the company that
 * handles the ticket; requesters can always see and reply to their own tickets (never internal notes).
 */
class TicketService
{
    /** File types accepted as attachments (a security allow-list, not a business setting). */
    public const MIMES = ['jpg', 'jpeg', 'png', 'pdf'];

    public static function maxFiles(): int
    {
        return \App\Support\BusinessRules::int('tickets.max_files');
    }

    public static function maxKb(): int
    {
        return \App\Support\BusinessRules::int('tickets.max_file_mb') * 1024;
    }

    /** @param  list<UploadedFile>  $files */
    public function open(User $requester, TicketCategory $category, string $subject, string $body, ?TicketPriority $priority = null, ?Booking $booking = null, array $files = []): Ticket
    {
        if (! TicketCategory::query()->for($requester)->whereKey($category->id)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Please choose a valid category.']);
        }
        if ($booking && $booking->user_id !== $requester->id && ! $requester->can('view', $booking)) {
            throw ValidationException::withMessages(['booking_id' => 'Please choose one of your bookings.']);
        }
        if ($category->requires_booking && ! $booking) {
            throw ValidationException::withMessages(['booking_id' => 'Please choose the booking this is about.']);
        }
        $priority = $priority && ($priority->customer_selectable || $requester->hasAnyRole(\App\Enums\Role::staff())) ? $priority
            : ($category->defaultPriority ?? TicketPriority::default());

        // Operator issues go to the operator of the booked station; everything else to Trans EV.
        $platformId = Company::query()->where('is_platform', true)->value('id');
        $handler = $category->handled_by === 'operator' && $booking ? $booking->company_id : $platformId;

        return DB::transaction(function () use ($requester, $category, $subject, $body, $priority, $booking, $files, $handler) {
            $now = now();
            $ticket = Ticket::create([
                'company_id' => $handler, 'requester_id' => $requester->id, 'requester_company_id' => $requester->company_id,
                'category_id' => $category->id, 'priority_id' => $priority->id, 'booking_id' => $booking?->id, 'station_id' => $booking?->station_id,
                'subject' => Str::limit(trim(strip_tags($subject)), 160, ''), 'status' => 'open',
                'first_response_due_at' => $now->copy()->addMinutes($priority->first_response_minutes),
                'resolution_due_at' => $now->copy()->addMinutes($priority->resolution_minutes),
                'last_activity_at' => $now, 'last_reply_by' => 'requester',
            ]);
            $this->addMessage($ticket, $requester, $body, false, $files);
            DB::afterCommit(fn () => $this->notifyTeam($ticket->fresh(), 'ticket.new'));

            return $ticket->fresh();
        });
    }

    /** Requester or agent reply. Agents may post internal notes and set the next status in one go. */
    public function reply(Ticket $ticket, User $by, string $body, bool $internal = false, array $files = [], ?string $status = null): TicketMessage
    {
        $isRequester = $by->id === $ticket->requester_id;
        $isAgent = $this->canWork($by, $ticket);
        abort_unless($isRequester || $isAgent, 403);
        if ($ticket->status === 'closed' && ! ($isAgent && $by->can('tickets.manage'))) {
            throw ValidationException::withMessages(['body' => 'This ticket is closed. Please open a new ticket.']);
        }
        $internal = $internal && $isAgent;

        return DB::transaction(function () use ($ticket, $by, $body, $internal, $files, $status, $isAgent, $isRequester) {
            $message = $this->addMessage($ticket, $by, $body, $internal, $files);
            $changes = ['last_activity_at' => now()];
            if (! $internal) {
                if ($isAgent && ! $isRequester) {
                    $changes['last_reply_by'] = 'agent';
                    $changes['first_responded_at'] = $ticket->first_responded_at ?? now();
                    $changes['status'] = $status && array_key_exists($status, Ticket::STATUSES) ? $status : 'waiting_on_customer';
                } else {
                    $changes['last_reply_by'] = 'requester';
                    // A reply re-opens a resolved ticket.
                    $changes['status'] = in_array($ticket->status, ['resolved', 'waiting_on_customer'], true) ? 'open' : $ticket->status;
                }
            } elseif ($status && array_key_exists($status, Ticket::STATUSES)) {
                $changes['status'] = $status;
            }
            $this->applyStatusDates($ticket, $changes);
            $ticket->forceFill($changes)->save();

            DB::afterCommit(function () use ($ticket, $by, $internal, $isAgent, $isRequester) {
                $t = $ticket->fresh(['requester', 'assignee']);
                if ($internal) {
                    return;
                }
                if ($isAgent && ! $isRequester) {
                    $t->requester?->notify(new TicketUpdate($t, 'ticket.agent_reply'));
                } elseif ($t->assignee && $t->assignee->id !== $by->id) {
                    $t->assignee->notify(new TicketUpdate($t, 'ticket.requester_reply'));
                } else {
                    $this->notifyTeam($t, 'ticket.requester_reply');
                }
            });

            return $message;
        });
    }

    public function assign(Ticket $ticket, ?User $agent, User $by): void
    {
        abort_unless($by->can('tickets.assign') && Tenancy::owns($ticket->company_id, $by), 403);
        if ($agent && ! $this->eligibleAgents($ticket)->contains('id', $agent->id)) {
            throw ValidationException::withMessages(['assigned_to' => 'This person cannot work on tickets for this company.']);
        }
        DB::transaction(function () use ($ticket, $agent, $by) {
            $ticket->forceFill([
                'assigned_to' => $agent?->id,
                'status' => $agent && $ticket->status === 'open' ? 'in_progress' : $ticket->status,
                'last_activity_at' => now(),
            ])->save();
            $this->event($ticket, $by, $agent ? "Assigned to {$agent->name}" : 'Unassigned');
        });
        if ($agent && $agent->id !== $by->id) {
            $agent->notify(new TicketUpdate($ticket->fresh(), 'ticket.assigned'));
        }
    }

    public function setStatus(Ticket $ticket, string $status, User $by): void
    {
        abort_unless(array_key_exists($status, Ticket::STATUSES), 422);
        $isRequester = $by->id === $ticket->requester_id;
        $isAgent = $this->canWork($by, $ticket);
        // Requesters may mark their own ticket resolved/closed or reopen a resolved one; agents may set any state,
        // reopening a closed ticket needs tickets.manage.
        $allowed = $isAgent
            ? ($ticket->status !== 'closed' || $by->can('tickets.manage'))
            : ($isRequester && (in_array($status, ['resolved', 'closed'], true) || ($status === 'open' && $ticket->status === 'resolved')));
        abort_unless($allowed, 403);

        DB::transaction(function () use ($ticket, $status, $by) {
            $changes = ['status' => $status, 'last_activity_at' => now()];
            $this->applyStatusDates($ticket, $changes);
            $ticket->forceFill($changes)->save();
            $this->event($ticket, $by, 'Status: '.Ticket::STATUSES[$status]);
        });
        if ($by->id !== $ticket->requester_id && in_array($status, ['resolved', 'closed'], true)) {
            $t = $ticket->fresh('requester');
            $t->requester?->notify(new TicketUpdate($t, 'ticket.status'));
        }
    }

    /** Close tickets resolved more than N days ago (N from config/settings). */
    public function autoClose(): int
    {
        $days = \App\Support\BusinessRules::int('tickets.auto_close_days');

        return Ticket::query()->where('status', 'resolved')->where('resolved_at', '<=', now()->subDays($days))->get()
            ->each(function (Ticket $t) {
                $t->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
                $this->event($t, null, 'Closed automatically after resolution');
            })->count();
    }

    /** Staff who can work on this ticket: tickets.reply in the handling company, or platform-wide agents. */
    public function eligibleAgents(Ticket $ticket): Collection
    {
        return User::query()->whereNull('blocked_at')->permission('tickets.reply')->with('company')->get()
            ->filter(fn (User $u) => $u->company_id === $ticket->company_id || Tenancy::isPlatformAdmin($u))->values();
    }

    public function canWork(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.reply') && Tenancy::owns($ticket->company_id, $user);
    }

    public function canView(User $user, Ticket $ticket): bool
    {
        return $user->id === $ticket->requester_id
            || ($user->can('tickets.view') && (Tenancy::owns($ticket->company_id, $user)
                || ($ticket->requester_company_id !== null && $ticket->requester_company_id === $user->company_id)));
    }

    /** @param  list<UploadedFile>  $files */
    private function addMessage(Ticket $ticket, ?User $by, string $body, bool $internal, array $files): TicketMessage
    {
        $stored = [];
        foreach (array_slice(array_filter($files), 0, self::maxFiles()) as $file) {
            $ext = strtolower($file->guessExtension() ?: 'bin');
            if (! in_array($ext, self::MIMES, true)) {
                continue;
            }
            $path = $file->storeAs("tickets/{$ticket->id}", Str::uuid().'.'.$ext, 'local');
            $stored[] = [
                'path' => $path, 'name' => Str::limit(preg_replace('/[^\w.\- ]/u', '_', $file->getClientOriginalName()), 120, ''),
                'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            ];
        }

        return TicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $by?->id, 'body' => trim(Str::limit($body, 10000, '')),
            'is_internal' => $internal, 'attachments' => $stored ?: null,
        ]);
    }

    private function event(Ticket $ticket, ?User $by, string $text): void
    {
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $by?->id, 'body' => $text, 'is_internal' => true, 'event' => 'system']);
    }

    private function applyStatusDates(Ticket $ticket, array &$changes): void
    {
        $status = $changes['status'] ?? $ticket->status;
        if ($status === 'resolved' && ! $ticket->resolved_at) {
            $changes['resolved_at'] = now();
        }
        if ($status === 'closed') {
            $changes['closed_at'] = now();
            $changes['resolved_at'] = $ticket->resolved_at ?? now();
        }
        if (in_array($status, Ticket::ACTIVE, true)) {
            $changes['resolved_at'] = null;
            $changes['closed_at'] = null;
        }
    }

    private function statusLine(Ticket $t): string
    {
        return 'Status: '.Ticket::STATUSES[$t->status].'.';
    }

    /** People who triage tickets for the handling company (tickets.assign), capped to avoid mass email. */
    private function notifyTeam(Ticket $ticket, string $template): void
    {
        User::query()->whereNull('blocked_at')->permission('tickets.assign')->where('company_id', $ticket->company_id)->limit(10)->get()
            ->each(fn (User $u) => $u->notify(new TicketUpdate($ticket, $template)));
    }

    public static function attachmentStream(array $file): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return Storage::disk('local')->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}

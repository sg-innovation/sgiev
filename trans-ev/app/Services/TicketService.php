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

    /**
     * Ticket for someone without an account (contact form). They are the requester by name and email, get an email
     * with a private link to the thread, and can reply there or by replying to our emails.
     *
     * @param  list<UploadedFile>  $files
     */
    public function openForGuest(string $name, string $email, ?string $phone, TicketCategory $category, string $subject, string $body, ?Booking $booking = null, array $files = []): Ticket
    {
        if ($category->requires_booking && ! $booking) {
            $category = TicketCategory::query()->for(null)->where('requires_booking', false)->firstOrFail();
        }
        $priority = $category->defaultPriority ?? TicketPriority::default();
        $platformId = Company::query()->where('is_platform', true)->value('id');
        $handler = $category->handled_by === 'operator' && $booking ? $booking->company_id : $platformId;
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($name, $email, $phone, $category, $subject, $body, $booking, $files, $handler, $priority) {
            $now = now();
            $ticket = Ticket::create([
                'company_id' => $handler, 'requester_id' => null, 'requester_company_id' => null,
                'guest_name' => Str::limit(trim(strip_tags($name)), 120, ''), 'guest_email' => $email, 'guest_phone' => $phone,
                'category_id' => $category->id, 'priority_id' => $priority->id, 'booking_id' => $booking?->id, 'station_id' => $booking?->station_id,
                'subject' => Str::limit(trim(strip_tags($subject)), 160, ''), 'status' => 'open', 'channel' => 'contact',
                'first_response_due_at' => $now->copy()->addMinutes($priority->first_response_minutes),
                'resolution_due_at' => $now->copy()->addMinutes($priority->resolution_minutes),
                'last_activity_at' => $now, 'last_reply_by' => 'requester',
            ]);
            $this->addMessage($ticket, null, $body, false, $files, 'guest', $ticket->guest_name, $email);
            DB::afterCommit(function () use ($ticket) {
                $t = $ticket->fresh();
                $this->notifyRequester($t, 'ticket.received');
                $this->notifyTeam($t, 'ticket.new');
            });

            return $ticket->fresh();
        });
    }

    /**
     * A guest requester's reply (from their private link or by email). Same effect as a customer reply: re-opens a
     * resolved ticket and tells the assignee or the team.
     *
     * @param  list<UploadedFile>  $files
     */
    public function guestReply(Ticket $ticket, string $body, array $files = [], string $via = 'guest'): TicketMessage
    {
        abort_unless($ticket->isGuest(), 404);
        if ($ticket->status === 'closed') {
            throw ValidationException::withMessages(['body' => 'This ticket is closed. Please send us a new message.']);
        }

        return DB::transaction(function () use ($ticket, $body, $files, $via) {
            $message = $this->addMessage($ticket, null, $body, false, $files, $via, $ticket->guest_name, $ticket->guest_email);
            $changes = ['last_activity_at' => now(), 'last_reply_by' => 'requester',
                'status' => in_array($ticket->status, ['resolved', 'waiting_on_customer'], true) ? 'open' : $ticket->status];
            $this->applyStatusDates($ticket, $changes);
            $ticket->forceFill($changes)->save();
            DB::afterCommit(function () use ($ticket) {
                $t = $ticket->fresh(['assignee']);
                $t->assignee ? $t->assignee->notify(new TicketUpdate($t, 'ticket.requester_reply')) : $this->notifyTeam($t, 'ticket.requester_reply');
            });

            return $message;
        });
    }

    /** A guest marks their ticket solved / closed (with an optional rating) or reopens a resolved one. */
    public function guestStatus(Ticket $ticket, string $status, ?int $rating = null): void
    {
        abort_unless($ticket->isGuest(), 404);
        $ok = in_array($status, ['resolved', 'closed'], true) ? $ticket->status !== 'closed' : ($status === 'open' && $ticket->status === 'resolved');
        abort_unless($ok, 403);
        DB::transaction(function () use ($ticket, $status, $rating) {
            $changes = ['status' => $status, 'last_activity_at' => now()] + ($rating ? ['rating' => $rating] : []);
            $this->applyStatusDates($ticket, $changes);
            $ticket->forceFill($changes)->save();
            $this->event($ticket, null, 'Status: '.Ticket::STATUSES[$status].' (by the requester)');
        });
    }

    /** Email the requester (account holder through their channels, or the guest by email). */
    public function notifyRequester(Ticket $ticket, string $template, array $vars = []): void
    {
        $ticket->requesterNotifiable()?->notify(new TicketUpdate($ticket, $template, $vars));
    }

    /** Requester or agent reply. Agents may post internal notes and set the next status in one go. */
    public function reply(Ticket $ticket, User $by, string $body, bool $internal = false, array $files = [], ?string $status = null, string $via = 'web'): TicketMessage
    {
        $isRequester = $by->id === $ticket->requester_id;
        $isAgent = $this->canWork($by, $ticket);
        abort_unless($isRequester || $isAgent, 403);
        if ($ticket->status === 'closed' && ! ($isAgent && $by->can('tickets.manage'))) {
            throw ValidationException::withMessages(['body' => 'This ticket is closed. Please open a new ticket.']);
        }
        $internal = $internal && $isAgent;

        return DB::transaction(function () use ($ticket, $by, $body, $internal, $files, $status, $isAgent, $isRequester, $via) {
            $message = $this->addMessage($ticket, $by, $body, $internal, $files, $via);
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

            DB::afterCommit(function () use ($ticket, $by, $internal, $isAgent, $isRequester, $body) {
                $t = $ticket->fresh(['requester', 'assignee']);
                if ($internal) {
                    return;
                }
                if ($isAgent && ! $isRequester) {
                    $this->notifyRequester($t, 'ticket.agent_reply', ['message' => self::excerpt($body)]);
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
            $this->notifyRequester($ticket->fresh('requester'), 'ticket.status');
        }
    }

    /**
     * D2 SLA watcher (scheduled every 5 minutes): warn the assignee shortly before a deadline, then on a breach mark it,
     * raise the escalation level and alert the support leads (tickets.manage) of the handling company and Trans EV.
     * Returns the number of tickets that breached in this run.
     */
    public function checkSla(): int
    {
        $warn = \App\Support\BusinessRules::int('tickets.sla_warning_minutes');
        $breached = 0;
        Ticket::query()->whereIn('status', ['open', 'in_progress'])->whereNull('sla_paused_at')->with(['assignee', 'priority'])->get()
            ->each(function (Ticket $t) use ($warn, &$breached) {
                $firstDue = ! $t->first_responded_at && $t->first_response_due_at ? $t->first_response_due_at : null;
                if ($firstDue && $firstDue->isPast() && ! $t->first_response_breached_at) {
                    $this->breach($t, 'first_response_breached_at', 'first reply');
                    $breached++;

                    return;
                }
                if ($t->resolution_due_at && $t->resolution_due_at->isPast() && ! $t->resolution_breached_at) {
                    $this->breach($t, 'resolution_breached_at', 'resolution');
                    $breached++;

                    return;
                }
                $next = collect([$firstDue, $t->resolution_breached_at ? null : $t->resolution_due_at])->filter()->min();
                if ($warn > 0 && $next && ! $t->sla_warned_at && $next->isFuture() && $next->lte(now()->addMinutes($warn))) {
                    $t->forceFill(['sla_warned_at' => now()])->save();
                    $this->event($t, null, 'SLA due at '.$next->timezone(\App\Models\Station::TIMEZONE)->format('g:i A'));
                    $to = $t->assignee ? collect([$t->assignee]) : $this->leads($t);
                    $to->each(fn (User $u) => $u->notify(new TicketUpdate($t, 'ticket.sla_warning', ['due' => $next->timezone(\App\Models\Station::TIMEZONE)->format('j M, g:i A')])));
                }
            });

        return $breached;
    }

    private function breach(Ticket $t, string $field, string $what): void
    {
        $t->forceFill([$field => now(), 'escalation_level' => $t->escalation_level + 1, 'escalated_at' => now()])->save();
        $this->event($t, null, "SLA breached ({$what}); escalated to level {$t->escalation_level}");
        $recipients = $this->leads($t)->merge($t->assignee ? [$t->assignee] : [])->unique('id');
        $recipients->each(fn (User $u) => $u->notify(new TicketUpdate($t, 'ticket.sla_breach', ['what' => $what, 'level' => (string) $t->escalation_level])));
    }

    /** Support leads: tickets.manage in the handling company, plus Trans EV leads for operator-handled tickets. */
    private function leads(Ticket $t): Collection
    {
        $platform = Company::query()->where('is_platform', true)->value('id');

        return User::query()->whereNull('blocked_at')->permission('tickets.manage')->whereIn('company_id', array_unique([$t->company_id, $platform]))->limit(20)->get();
    }

    /**
     * Contact form → ticket (every query becomes a threaded ticket). Signed-in customers get a ticket in their account;
     * guests get a guest ticket with a private link by email. No account is created for them.
     */
    public function fromContactMessage(\App\Models\ContactMessage $msg, ?User $by = null): Ticket
    {
        if ($msg->ticket_id) {
            return Ticket::query()->findOrFail($msg->ticket_id);
        }
        $user = $msg->user;
        if (! $user) {
            $category = TicketCategory::query()->for(null)->where('contact_topic', $msg->topic)->first()
                ?? TicketCategory::query()->for(null)->where('requires_booking', false)->firstOrFail();
            // A booking is linked only when the code matches a booking made with the same email address.
            $booking = $msg->booking_code ? Booking::query()->where('code', $msg->booking_code)
                ->where(fn ($q) => $q->whereRelation('user', 'email', strtolower($msg->email))->orWhere('customer_snapshot->email', strtolower($msg->email)))->first() : null;
            $ticket = $this->openForGuest($msg->name, $msg->email, $msg->phone, $category, $msg->subject,
                $msg->message.($msg->booking_code && ! $booking ? "\n\nBooking code given: {$msg->booking_code}" : ''), $booking);
            $ticket->forceFill(['contact_message_id' => $msg->id])->save();
            $msg->forceFill(['ticket_id' => $ticket->id, 'status' => 'resolved', 'resolved_at' => now(),
                'internal_notes' => trim(($msg->internal_notes ?? '')."\nConverted to ticket {$ticket->number}".($by ? " by {$by->name}" : ''))])->save();
            $this->event($ticket, $by, 'Created from contact form message '.$msg->reference);

            return $ticket;
        }
        $category = TicketCategory::query()->for($user)->where('contact_topic', $msg->topic)->first()
            ?? TicketCategory::query()->for($user)->where('requires_booking', false)->orderBy('sort')->firstOrFail();
        $booking = $msg->booking_code ? Booking::query()->where('code', $msg->booking_code)->where('user_id', $user->id)->first() : null;
        if ($category->requires_booking && ! $booking) {
            $category = TicketCategory::query()->for($user)->where('requires_booking', false)->orderBy('sort')->firstOrFail();
        }
        $ticket = $this->open($user, $category, $msg->subject, $msg->message.($msg->booking_code && ! $booking ? "\n\nBooking code given: {$msg->booking_code}" : ''), null, $booking);
        $ticket->forceFill(['channel' => 'contact', 'contact_message_id' => $msg->id])->save();
        $msg->forceFill(['ticket_id' => $ticket->id, 'status' => 'resolved', 'resolved_at' => now(),
            'internal_notes' => trim(($msg->internal_notes ?? '')."\nConverted to ticket {$ticket->number}".($by ? " by {$by->name}" : ''))])->save();
        $this->event($ticket, $by, 'Created from contact form message '.$msg->reference);

        return $ticket;
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
    private function addMessage(Ticket $ticket, ?User $by, string $body, bool $internal, array $files, string $via = 'web', ?string $authorName = null, ?string $authorEmail = null): TicketMessage
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
            'via' => $via, 'author_name' => $authorName, 'author_email' => $authorEmail,
        ]);
    }

    private function event(Ticket $ticket, ?User $by, string $text): void
    {
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $by?->id, 'body' => $text, 'is_internal' => true, 'event' => 'system']);
    }

    private function applyStatusDates(Ticket $ticket, array &$changes): void
    {
        $status = $changes['status'] ?? $ticket->status;
        // D2: the SLA clock stops while we wait on the requester and while resolved; the resolution deadline moves by the paused time.
        $paused = in_array($status, ['waiting_on_customer', 'resolved', 'closed'], true);
        if ($paused && ! $ticket->sla_paused_at) {
            $changes['sla_paused_at'] = now();
        } elseif (! $paused && $ticket->sla_paused_at) {
            $mins = (int) $ticket->sla_paused_at->diffInMinutes(now(), true);
            $changes['sla_paused_at'] = null;
            $changes['sla_paused_minutes'] = $ticket->sla_paused_minutes + $mins;
            if ($ticket->resolution_due_at && ! $ticket->resolution_breached_at) {
                $changes['resolution_due_at'] = $ticket->resolution_due_at->copy()->addMinutes($mins);
            }
            $changes['sla_warned_at'] = null;
        }
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

    /** First part of a message for notification emails (plain text, never internal notes). */
    public static function excerpt(string $body, int $limit = 1200): string
    {
        return Str::limit(trim(preg_replace("/\n{3,}/", "\n\n", strip_tags($body))), $limit);
    }

    public static function attachmentStream(array $file): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return Storage::disk('local')->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}

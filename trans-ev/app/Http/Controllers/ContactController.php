<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Honeypot: real users never fill the hidden "website" field.
        if (filled($request->input('website'))) {
            return back()->with('success', 'Thank you! Your message has been received.');
        }

        $request->merge(['phone' => preg_replace('/\D/', '', (string) $request->input('phone')) ?: null]);

        $data = $request->validate([
            'topic' => ['required', Rule::in(array_keys(ContactMessage::topics()))],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'booking_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'subject' => ['required', 'string', 'min:4', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'consent' => ['accepted'],
        ], [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'consent.accepted' => 'Please allow us to use these details to respond to your query.',
        ]);

        $msg = ContactMessage::create([
            ...collect($data)->except('consent')->all(),
            'booking_code' => isset($data['booking_code']) ? strtoupper($data['booking_code']) : null,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // Every query becomes a threaded ticket: in the customer's account, or a guest ticket with a private link by email.
        if (! $request->user()?->isStaff()) {
            try {
                $ticket = app(\App\Services\TicketService::class)->fromContactMessage($msg);

                return back()->with('success', $ticket->isGuest()
                    ? "Thank you! We’ve opened support ticket {$ticket->number} and emailed you a private link to follow it. You can also reply to that email."
                    : "Thank you! We’ve opened support ticket {$ticket->number}. Follow it in My account → Support tickets.");
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Contact to ticket failed', ['ref' => $msg->reference, 'error' => $e->getMessage()]);
            }
        }

        return back()->with('success', "Thank you! Your query has been received. Reference: {$msg->reference}. We usually reply within one working day.");
    }
}

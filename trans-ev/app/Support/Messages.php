<?php

namespace App\Support;

use App\Models\MessageTemplate;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Email and SMS wording stored in the database (message_templates), edited in Admin → Content → Message templates.
 * TEMPLATES lists every message, the {{variables}} it can use and its first-install wording (seeded once).
 *
 * Mail body format: paragraphs separated by a blank line. A paragraph starting with "# " becomes the greeting.
 */
final class Messages
{
    public const TEMPLATES = [
        'booking.confirmed' => [
            'label' => 'Booking confirmed (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station name', 'slot' => 'Date and time', 'charger' => 'Charger', 'amount' => 'Amount paid', 'payment_ref' => 'Payment reference', 'no_show_minutes' => 'No-show window'],
            'mail' => ['subject' => 'Booking confirmed: {{booking_code}}', 'action_label' => 'View booking & receipt', 'body' => "# Your charging slot is confirmed\n\n{{station}}\n\n{{slot}} (IST)\n\nCharger {{charger}}\n\nAmount paid: ₹{{amount}} (ref. {{payment_ref}})\n\nPlease arrive on time. The slot may be released if charging does not start within {{no_show_minutes}} minutes of the start time."],
            'sms' => ['body' => 'Trans EV: booking {{booking_code}} confirmed at {{station}}, {{slot}}. Paid Rs {{amount}}.'],
        ],
        'gst.invoice' => [
            'label' => 'GST tax invoice issued (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'number' => 'Invoice number', 'amount' => 'Invoice total'],
            'mail' => ['subject' => 'Tax invoice {{number}} · Trans EV booking {{booking_code}}', 'action_label' => 'View booking', 'body' => "# Hello {{name}},\n\nYour GST tax invoice for booking {{booking_code}} is attached.\n\nAmount: ₹{{amount}}"],
        ],
        'gst.credit_note' => [
            'label' => 'GST credit note issued (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'number' => 'Credit note number', 'amount' => 'Credit note total'],
            'mail' => ['subject' => 'Credit note {{number}} · Trans EV booking {{booking_code}}', 'action_label' => 'View booking', 'body' => "# Hello {{name}},\n\nA credit note for booking {{booking_code}} is attached. It reverses ₹{{amount}} of the original invoice because of a refund."],
        ],
        'gst.debit_note' => [
            'label' => 'GST debit note issued (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'number' => 'Debit note number', 'amount' => 'Debit note total'],
            'mail' => ['subject' => 'Debit note {{number}} · Trans EV booking {{booking_code}}', 'action_label' => 'View booking', 'body' => "# Hello {{name}},\n\nA debit note for booking {{booking_code}} is attached. It adds ₹{{amount}} to the original invoice for the extra energy delivered, which you have paid."],
        ],
        'waitlist.offer' => [
            'label' => 'Waiting list: a slot opened up (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station', 'address' => 'Station address', 'slot' => 'Date and time', 'charger' => 'Charger', 'minutes' => 'Minutes to pay', 'expires' => 'Offer ends at'],
            'mail' => ['subject' => 'A slot opened up at {{station}} · pay within {{minutes}} minutes', 'action_label' => 'Pay and confirm', 'body' => "# Good news, {{name}}!\n\nA charging slot you were waiting for is free at **{{station}}**:\n\n**{{slot}}** (IST), charger {{charger}}\n\nWe are holding it for you until **{{expires}}**. Pay before then to confirm; after that it goes to the next person in line."],
        ],
        'booking.rescheduled' => [
            'label' => 'Booking moved to a new time (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station', 'address' => 'Station address', 'slot' => 'New date and time', 'old_slot' => 'Previous start', 'charger' => 'Charger', 'changed_by' => 'Who changed it', 'reason' => 'Reason'],
            'mail' => ['subject' => 'New time for your charging booking · {{booking_code}}', 'action_label' => 'View booking', 'body' => "# Hello {{name}},\n\nYour booking at **{{station}}** was moved by {{changed_by}}.\n\n**New time:** {{slot}} (IST), charger {{charger}}\n\nPreviously: {{old_slot}}\n\n{{reason}}\n\nYour payment and receipt stay the same."],
        ],
        'booking.reminder' => [
            'label' => 'Booking reminder before the slot (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station', 'address' => 'Station address', 'slot' => 'Date and time', 'start_time' => 'Start time', 'charger' => 'Charger', 'minutes' => 'Minutes to the slot', 'no_show_minutes' => 'Grace minutes'],
            'mail' => ['subject' => 'Your charging slot starts in {{minutes}} minutes · {{booking_code}}', 'action_label' => 'Open booking & check-in pass', 'body' => "# Hello {{name}},\n\nYour slot at **{{station}}** ({{address}}) starts at **{{start_time}}**.\n\nCharger {{charger}}. Open your booking to show the check-in pass, or scan the QR on the charger.\n\nRunning late? Tap **I’m running late** on your booking so the station knows. If you don’t arrive within {{no_show_minutes}} minutes of the start, the slot may be released as a no-show."],
        ],
        'booking.starting' => [
            'label' => 'Booking starting now (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station', 'address' => 'Station address', 'slot' => 'Date and time', 'start_time' => 'Start time', 'charger' => 'Charger', 'no_show_minutes' => 'Grace minutes'],
            'mail' => ['subject' => 'Your charging slot has started · {{booking_code}}', 'action_label' => 'Open booking', 'body' => "# Hello {{name}},\n\nYour slot at **{{station}}** has started. Charger {{charger}} is waiting for you.\n\nIf you’re delayed, tap **I’m running late** on your booking. The slot is held for {{no_show_minutes}} minutes."],
        ],
        'booking.no_show' => [
            'label' => 'Booking marked as no-show (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station', 'slot' => 'Date and time', 'grace_minutes' => 'Grace given', 'percent' => 'Refund %', 'refund' => 'Refund amount', 'retained' => 'Amount kept'],
            'mail' => ['subject' => 'Missed charging slot · {{booking_code}}', 'action_label' => 'View booking', 'body' => "# Hello {{name}},\n\nWe didn’t see you at **{{station}}** within {{grace_minutes}} minutes of your slot ({{slot}}), so it was released for other drivers.\n\nUnder the no-show policy you agreed to, ₹{{refund}} ({{percent}}%) is being refunded to your original payment method and ₹{{retained}} is kept.\n\nWe hope to see you again soon."],
        ],
        'booking.trueup' => [
            'label' => 'Charging summary and final bill (to customer)',
            'vars' => ['name' => 'Customer name', 'booking_code' => 'Booking code', 'station' => 'Station', 'kwh' => 'Energy delivered (kWh)', 'prepaid' => 'Amount prepaid', 'final' => 'Final bill', 'outcome' => 'Refund or balance sentence'],
            'mail' => ['subject' => 'Your charging summary · {{booking_code}}', 'action_label' => 'View booking', 'body' => "# Hello {{name}},\n\nThanks for charging at {{station}}. Your vehicle received **{{kwh}} kWh**.\n\n- Prepaid: ₹{{prepaid}}\n- Final bill for the energy delivered: ₹{{final}}\n\n{{outcome}}"],
        ],
        'partner.approved' => [
            'label' => 'Partner company approved',
            'vars' => ['name' => 'Recipient name', 'company' => 'Company name'],
            'mail' => ['subject' => 'Your Trans EV partner account is approved', 'action_label' => 'Open your partner dashboard', 'body' => "# Hello {{name}},\n\nYour company {{company}} has been approved.\n\nSubmit your stations for review from the admin panel. Each station goes live once it is approved."],
        ],
        'partner.changes_requested' => [
            'label' => 'Partner application: changes requested',
            'vars' => ['name' => 'Recipient name', 'company' => 'Company name', 'note' => 'Reviewer’s note'],
            'mail' => ['subject' => 'Action needed on your Trans EV partner application', 'action_label' => 'Open your partner dashboard', 'body' => "# Hello {{name}},\n\nPlease update your application for {{company}}:\n\n{{note}}"],
        ],
        'partner.rejected' => [
            'label' => 'Partner application rejected',
            'vars' => ['name' => 'Recipient name', 'company' => 'Company name', 'note' => 'Reason'],
            'mail' => ['subject' => 'Your Trans EV partner application', 'action_label' => 'Open your partner dashboard', 'body' => "# Hello {{name}},\n\nWe are unable to approve the application for {{company}} at this time.\n\n{{note}}"],
        ],
        'station.approved' => [
            'label' => 'Station approved',
            'vars' => ['name' => 'Recipient name', 'station' => 'Station name'],
            'mail' => ['subject' => '{{station}} is approved', 'action_label' => 'Open your partner dashboard', 'body' => "# Hello {{name}},\n\n{{station}} is now visible to customers and open for bookings (once it is set to Online and published)."],
        ],
        'station.rejected' => [
            'label' => 'Station not approved',
            'vars' => ['name' => 'Recipient name', 'station' => 'Station name', 'note' => 'Reason'],
            'mail' => ['subject' => '{{station}} needs changes', 'action_label' => 'Open your partner dashboard', 'body' => "# Hello {{name}},\n\nYour station was not approved:\n\n{{note}}"],
        ],
        'ticket.new' => [
            'label' => 'Support: new ticket (to triage team)',
            'vars' => ['name' => 'Recipient name', 'number' => 'Ticket number', 'subject' => 'Ticket subject', 'requester' => 'Requester name'],
            'mail' => ['subject' => 'New ticket {{number}}: {{subject}}', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\n{{requester}} raised a new ticket.\n\nTicket: {{number}} · {{subject}}"],
        ],
        'ticket.agent_reply' => [
            'label' => 'Support: reply from support (to requester)',
            'vars' => ['name' => 'Requester name', 'number' => 'Ticket number', 'subject' => 'Ticket subject', 'status' => 'Ticket status'],
            'mail' => ['subject' => 'Reply on your ticket {{number}}', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\nWe have replied to your ticket.\n\nTicket: {{number}} · {{subject}}\n\nStatus: {{status}}."],
            'sms' => ['body' => 'Trans EV support replied to your ticket {{number}}. Open your account to read it.'],
        ],
        'ticket.requester_reply' => [
            'label' => 'Support: requester replied (to agent)',
            'vars' => ['name' => 'Agent name', 'number' => 'Ticket number', 'subject' => 'Ticket subject'],
            'mail' => ['subject' => 'Customer replied on {{number}}', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\nThe requester added a reply.\n\nTicket: {{number}} · {{subject}}"],
        ],
        'ticket.assigned' => [
            'label' => 'Support: ticket assigned (to agent)',
            'vars' => ['name' => 'Agent name', 'number' => 'Ticket number', 'subject' => 'Ticket subject'],
            'mail' => ['subject' => 'Ticket {{number}} assigned to you', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\nA ticket has been assigned to you.\n\nTicket: {{number}} · {{subject}}"],
        ],
        'ticket.sla_warning' => [
            'label' => 'Ticket SLA due soon (to agent)',
            'vars' => ['name' => 'Recipient name', 'number' => 'Ticket number', 'subject' => 'Subject', 'status' => 'Status', 'requester' => 'Requester', 'due' => 'Deadline'],
            'mail' => ['subject' => 'Due soon: ticket {{number}}', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\nTicket {{number}} ({{subject}}) from {{requester}} reaches its SLA deadline at **{{due}}**.\n\nPlease reply or update it before then."],
        ],
        'ticket.sla_breach' => [
            'label' => 'Ticket SLA breached and escalated (to agents and leads)',
            'vars' => ['name' => 'Recipient name', 'number' => 'Ticket number', 'subject' => 'Subject', 'status' => 'Status', 'requester' => 'Requester', 'what' => 'Which deadline', 'level' => 'Escalation level'],
            'mail' => ['subject' => 'SLA breached: ticket {{number}}', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\nTicket {{number}} ({{subject}}) from {{requester}} has missed its **{{what}}** deadline and is now escalated (level {{level}}).\n\nStatus: {{status}}. Please take it over or reassign it."],
        ],
        'ticket.status' => [
            'label' => 'Support: ticket resolved or closed (to requester)',
            'vars' => ['name' => 'Requester name', 'number' => 'Ticket number', 'subject' => 'Ticket subject', 'status' => 'New status'],
            'mail' => ['subject' => 'Ticket {{number}}: {{status}}', 'action_label' => 'Open ticket', 'body' => "# Hello {{name}},\n\nYour ticket is now {{status}}.\n\nTicket: {{number}} · {{subject}}\n\nIf you still need help, reply on the ticket to reopen it."],
        ],
        'platform_fees.issued' => [
            'label' => 'Platform-fee invoice issued (to operator)',
            'vars' => ['name' => 'Recipient name', 'company' => 'Operator', 'number' => 'Invoice number', 'period' => 'Month', 'total' => 'Invoice total', 'reimbursement' => 'Coupon reimbursement', 'net_payable' => 'Net payable', 'due_on' => 'Due date'],
            'mail' => ['subject' => 'Trans EV platform fees {{period}} · {{number}}', 'action_label' => 'View statements', 'body' => "# Hello {{name}},

Your platform-fee invoice for {{company}} for {{period}} is attached.

Invoice total: ₹{{total}}

Less platform coupon reimbursement: ₹{{reimbursement}}

Net payable: ₹{{net_payable}}, due by {{due_on}}."],
        ],
        'wallet.low_balance' => [
            'label' => 'Operator wallet balance low',
            'vars' => ['name' => 'Recipient name', 'company' => 'Operator', 'balance' => 'Current balance', 'blocked' => 'Note when a booking was refused'],
            'mail' => ['subject' => 'Recharge your Trans EV wallet', 'action_label' => 'Recharge wallet', 'body' => "# Hello {{name}},\n\nThe Trans EV wallet for {{company}} is running low: ₹{{balance}}.\n\n{{blocked}}\n\nCustomers can book your stations only while the wallet covers the platform fee for each booking. Please recharge to keep receiving bookings."],
        ],
        'reconciliation.alert' => [
            'label' => 'Reconciliation found problems (Trans EV staff)',
            'vars' => ['name' => 'Recipient name', 'count' => 'Number of new urgent exceptions', 'summary' => 'List of the exceptions'],
            'mail' => ['subject' => 'Reconciliation: {{count}} new payment exceptions', 'action_label' => 'Open reconciliation', 'body' => "# Hello {{name}},

Today's reconciliation found {{count}} new critical or high-priority exceptions:

{{summary}}

Please review them in Billing & GST → Reconciliation."],
        ],
        'auth.verify_email' => [
            'label' => 'Account: verify email address',
            'vars' => ['name' => 'User name'],
            'mail' => ['subject' => 'Verify your email address', 'action_label' => 'Verify email address', 'body' => "# Hello {{name}},\n\nPlease confirm your email address to start booking chargers on Trans EV.\n\nIf you did not create an account, no further action is required."],
        ],
        'auth.reset_password' => [
            'label' => 'Account: reset password',
            'vars' => ['name' => 'User name', 'expire_minutes' => 'Link validity (minutes)'],
            'mail' => ['subject' => 'Reset your Trans EV password', 'action_label' => 'Reset password', 'body' => "# Hello {{name}},\n\nWe received a request to reset the password for your account.\n\nThis link expires in {{expire_minutes}} minutes.\n\nIf you did not request a reset, you can ignore this email."],
        ],
    ];

    /** @var array<string, MessageTemplate|null>|null */
    private static ?array $cache = null;

    /** Build a mail message from the template (falls back to the first-install wording). */
    /**
     * D1: first-install SMS / WhatsApp / push wording for customer-facing messages. SMS in India must use DLT-registered
     * templates and WhatsApp must use Meta-approved templates: set their IDs/names in Admin → Message templates.
     * Push: subject = title, body = text.
     */
    public const CHANNEL_DEFAULTS = [
        'booking.confirmed' => [
            'whatsapp' => ['body' => 'Your Trans EV booking {{booking_code}} at {{station}} is confirmed for {{slot}}. Paid Rs {{amount}}.'],
            'push' => ['subject' => 'Booking confirmed', 'body' => '{{station}}, {{slot}}. Show your check-in pass when you arrive.'],
        ],
        'booking.reminder' => [
            'sms' => ['body' => 'Trans EV: your charging slot {{booking_code}} at {{station}} starts at {{start_time}}. Running late? Tell us in the app.'],
            'whatsapp' => ['body' => 'Reminder: your charging slot {{booking_code}} at {{station}} starts at {{start_time}}.'],
            'push' => ['subject' => 'Slot starts in {{minutes}} min', 'body' => '{{station}} at {{start_time}}, charger {{charger}}.'],
        ],
        'booking.starting' => [
            'push' => ['subject' => 'Your slot has started', 'body' => 'Charger {{charger}} at {{station}} is waiting for you.'],
        ],
        'booking.no_show' => [
            'sms' => ['body' => 'Trans EV: booking {{booking_code}} was released as a no-show. Rs {{refund}} is being refunded as per the policy.'],
            'push' => ['subject' => 'Missed slot', 'body' => 'Booking {{booking_code}} was released. Rs {{refund}} is being refunded.'],
        ],
        'booking.rescheduled' => [
            'sms' => ['body' => 'Trans EV: booking {{booking_code}} moved to {{slot}}, charger {{charger}}.'],
            'push' => ['subject' => 'Booking moved', 'body' => 'New time: {{slot}}, charger {{charger}}.'],
        ],
        'waitlist.offer' => [
            'sms' => ['body' => 'Trans EV: a slot opened at {{station}} ({{slot}}). Pay by {{expires}} to confirm, booking {{booking_code}}.'],
            'whatsapp' => ['body' => 'A charging slot opened at {{station}}: {{slot}}. Pay by {{expires}} to confirm booking {{booking_code}}.'],
            'push' => ['subject' => 'A slot opened up', 'body' => '{{station}}, {{slot}}. Pay within {{minutes}} minutes to confirm.'],
        ],
        'booking.trueup' => [
            'push' => ['subject' => 'Charging complete', 'body' => '{{kwh}} kWh delivered. Final bill Rs {{final}}.'],
        ],
        'ticket.agent_reply' => [
            'push' => ['subject' => 'Support replied', 'body' => 'New reply on ticket {{number}}: {{subject}}'],
        ],
    ];

    /** Built-in wording for a message on a channel (email/SMS from TEMPLATES, others from CHANNEL_DEFAULTS). */
    public static function definition(string $key, string $channel): ?array
    {
        return self::TEMPLATES[$key][$channel] ?? self::CHANNEL_DEFAULTS[$key][$channel] ?? null;
    }

    public static function mail(string $key, array $vars, ?string $actionUrl = null): MailMessage
    {
        $t = self::template($key, 'mail');
        $mail = (new MailMessage)->subject(self::fill($t['subject'] ?? '', $vars, plain: true));
        foreach (preg_split('/\R{2,}/', trim($t['body'] ?? '')) as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            str_starts_with($para, '# ') ? $mail->greeting(self::fill(substr($para, 2), $vars)) : $mail->line(self::fill($para, $vars));
        }
        if ($actionUrl && filled($t['action_label'] ?? null)) {
            $mail->action(self::fill($t['action_label'], $vars, plain: true), $actionUrl);
        }

        return $mail;
    }

    /** Whether a message is switched on (Admin → Message templates → “Send this message”). */
    public static function enabled(string $key, string $channel = 'mail'): bool
    {
        $row = self::stored()[$key.'|'.$channel] ?? null;

        return $row ? (bool) $row->is_active : self::definition($key, $channel) !== null;
    }

    /** Plain-text SMS body (null when there is no active SMS template). */
    public static function sms(string $key, array $vars): ?string
    {
        $t = self::template($key, 'sms');

        return filled($t['body'] ?? null) ? self::fill($t['body'], $vars, plain: true) : null;
    }

    /** @return array{subject?:string, body?:string, action_label?:string} */
    public static function template(string $key, string $channel): array
    {
        $row = self::stored()[$key.'|'.$channel] ?? null;
        if ($row !== null) {
            return $row->is_active ? ['subject' => $row->subject, 'body' => $row->body, 'action_label' => $row->action_label, 'dlt_template_id' => $row->dlt_template_id] : [];
        }

        return self::definition($key, $channel) ?? [];
    }

    /** Replace {{var}}. Variable values are escaped so customer-entered text cannot inject markup or links. */
    public static function fill(string $text, array $vars, bool $plain = false): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars, $plain) {
            $v = (string) ($vars[$m[1]] ?? '');

            return $plain ? strip_tags($v) : str_replace(['[', ']', '(', ')', '*', '_', '`', '<', '>', '#'], ['［', '］', '（', '）', '∗', '‗', 'ˋ', '‹', '›', '＃'], $v);
        }, $text);
    }

    private static function stored(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            self::$cache = Schema::hasTable('message_templates')
                ? MessageTemplate::query()->get()->keyBy(fn (MessageTemplate $t) => $t->key.'|'.$t->channel)->all() : [];
        } catch (Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}

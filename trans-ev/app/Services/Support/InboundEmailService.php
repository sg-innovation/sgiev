<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Email replies into ticket threads.
 *
 * Every ticket email has Reply-To support+TKT-000123@domain (address from Settings → Integrations → Support email).
 * The mail provider's inbound webhook posts each incoming email to /webhooks/inbound-email/{token}. We accept the
 * common payload shapes (Postmark, Mailgun routes, SendGrid Inbound Parse, Cloudflare/other JSON with from/to/
 * subject/text), find the ticket from the plus-address or the subject, check the sender is the requester or an agent
 * who can work the ticket, strip the quoted history and add the reply. Anything else is ignored and logged.
 */
class InboundEmailService
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function enabled(): bool
    {
        return filled(Settings::get('support.inbound_address')) && filled(Settings::get('support.inbound_token'));
    }

    /** support@domain → support+TKT-000123@domain (null when email replies are not set up). */
    public static function replyAddress(Ticket $ticket): ?string
    {
        $address = (string) Settings::get('support.inbound_address');
        if (! self::enabled() || ! $ticket->number || ! str_contains($address, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $address, 2);

        return Str::before($local, '+').'+'.$ticket->number.'@'.$domain;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<UploadedFile>  $uploads  attachments posted as files (Mailgun, SendGrid)
     * @return array{status: string, ticket?: string}
     */
    public function handle(array $payload, array $uploads = []): array
    {
        $mail = self::normalise($payload);
        $ticket = $this->findTicket($mail);
        if (! $ticket) {
            return $this->ignore('no_ticket', $mail);
        }
        $body = self::stripQuoted($mail['stripped'] ?: $mail['text']);
        if (mb_strlen($body) < 2) {
            return $this->ignore('empty', $mail, $ticket);
        }
        $files = array_merge($uploads, $this->decodeAttachments($mail['attachments']));
        $files = array_values(array_filter($files, fn (UploadedFile $f) => $f->getSize() <= TicketService::maxKb() * 1024));

        // Same text from the same sender in the last 10 minutes = a duplicate delivery.
        $dupe = TicketMessage::query()->where('ticket_id', $ticket->id)->where('via', 'email')->where('created_at', '>=', now()->subMinutes(10))
            ->where('body', trim(Str::limit($body, 10000, '')))->exists();
        if ($dupe) {
            return ['status' => 'duplicate', 'ticket' => $ticket->number];
        }

        $from = $mail['from'];
        try {
            if ($ticket->isGuest() && strcasecmp($from, (string) $ticket->guest_email) === 0) {
                $this->tickets->guestReply($ticket, $body, $files, 'email');

                return ['status' => 'added', 'ticket' => $ticket->number];
            }
            $user = User::query()->where('email', $from)->whereNull('blocked_at')->first();
            if ($user && ($user->id === $ticket->requester_id || $this->tickets->canWork($user, $ticket))) {
                $this->tickets->reply($ticket, $user, $body, false, $files, null, 'email');

                return ['status' => 'added', 'ticket' => $ticket->number];
            }
        } catch (\Illuminate\Validation\ValidationException) {
            return $this->ignore('closed', $mail, $ticket);
        }

        return $this->ignore('unknown_sender', $mail, $ticket);
    }

    /** @return array{from: string, name: ?string, to: list<string>, subject: string, text: string, stripped: string, attachments: list<array>} */
    public static function normalise(array $p): array
    {
        $first = fn (array $keys) => collect($keys)->map(fn ($k) => data_get($p, $k))->first(fn ($v) => filled($v));
        $fromRaw = (string) ($first(['FromFull.Email', 'from.email', 'from.address', 'sender', 'from', 'From']) ?? '');
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromRaw, $m);
        $to = collect([$first(['OriginalRecipient']), $first(['To', 'to', 'recipient']), $first(['envelope.to']), data_get($p, 'ToFull')])
            ->flatten()->map(fn ($v) => is_array($v) ? ($v['Email'] ?? $v['email'] ?? '') : (string) $v)
            ->flatMap(fn ($v) => preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $v, $mm) ? $mm[0] : [])->unique()->values()->all();

        return [
            'from' => strtolower($m[0] ?? ''),
            'name' => $first(['FromName', 'from.name']),
            'to' => $to,
            'subject' => (string) ($first(['Subject', 'subject']) ?? ''),
            'text' => (string) ($first(['TextBody', 'body-plain', 'text', 'plain', 'body.text']) ?? strip_tags((string) ($first(['HtmlBody', 'body-html', 'html']) ?? ''))),
            'stripped' => (string) ($first(['StrippedTextReply', 'stripped-text']) ?? ''),
            'attachments' => array_values(array_filter((array) ($p['Attachments'] ?? []), 'is_array')),
        ];
    }

    /** Keep only the new part of a reply: cut at "On … wrote:" / "Original message" and drop quoted (>) lines. */
    public static function stripQuoted(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $cuts = [
            '/^\s*On\b[^\n]{0,250}\n?[^\n]{0,250}\bwrote:\s*$/mi',
            '/^\s*-{2,}\s*(Original Message|Forwarded message)/mi',
            '/^\s*_{10,}\s*$/m',
            '/^\s*From:\s.*\n\s*(Sent|Date):\s/mi',
        ];
        foreach ($cuts as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $m[0][1]);
            }
        }
        $lines = array_filter(explode("\n", $text), fn ($l) => ! str_starts_with(ltrim($l), '>'));
        $text = implode("\n", $lines);
        // Signature delimiter "-- " (RFC 3676).
        $text = preg_split('/^-- ?$/m', $text)[0];

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function findTicket(array $mail): ?Ticket
    {
        foreach ($mail['to'] as $address) {
            if (preg_match('/\+(TKT-\d{6})@/i', $address, $m)) {
                return Ticket::query()->where('number', strtoupper($m[1]))->first();
            }
        }
        if (preg_match('/\bTKT-\d{6}\b/i', $mail['subject'], $m)) {
            return Ticket::query()->where('number', strtoupper($m[0]))->first();
        }

        return null;
    }

    /** Postmark-style base64 attachments → temporary uploaded files (types are checked again when stored). */
    private function decodeAttachments(array $attachments): array
    {
        $files = [];
        foreach (array_slice($attachments, 0, TicketService::maxFiles()) as $a) {
            $content = base64_decode((string) ($a['Content'] ?? $a['content'] ?? ''), true);
            if ($content === false || $content === '') {
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($path, $content);
            $files[] = new UploadedFile($path, (string) ($a['Name'] ?? $a['filename'] ?? 'attachment'), $a['ContentType'] ?? $a['content_type'] ?? null, null, true);
        }

        return $files;
    }

    private function ignore(string $reason, array $mail, ?Ticket $ticket = null): array
    {
        Log::info('Inbound support email ignored', ['reason' => $reason, 'from' => $mail['from'], 'ticket' => $ticket?->number, 'subject' => Str::limit($mail['subject'], 120)]);

        return ['status' => 'ignored_'.$reason] + ($ticket ? ['ticket' => $ticket->number] : []);
    }
}

<?php

namespace App\Notifications;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Notifications\Concerns\TemplatedMessage;
use App\Services\GstDocumentPdf;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sends the customer their GST invoice, credit note or debit note (PDF attached to the email). */
class GstDocumentIssued extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public Invoice|CreditNote $doc) {}

    public function messageKey(): string
    {
        return $this->doc instanceof Invoice ? 'gst.invoice' : ($this->doc->isDebit() ? 'gst.debit_note' : 'gst.credit_note');
    }

    public function messageVars(object $notifiable): array
    {
        return [
            'name' => $notifiable->name ?? ($this->doc->booking?->customer_snapshot['name'] ?? ''),
            'booking_code' => (string) $this->doc->booking?->code,
            'number' => $this->doc->number,
            'amount' => number_format((float) $this->doc->total, 2),
        ];
    }

    public function messageUrl(object $notifiable): ?string
    {
        return $this->doc->booking ? route('bookings.show', $this->doc->booking) : null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return Messages::mail($this->messageKey(), $this->messageVars($notifiable), $this->messageUrl($notifiable))
            ->attachData(app(GstDocumentPdf::class)->render($this->doc), $this->doc->filename(), ['mime' => 'application/pdf']);
    }
}

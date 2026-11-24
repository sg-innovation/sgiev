<?php

namespace App\Notifications;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\GstDocumentPdf;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails the customer their GST invoice or credit note as a PDF (wording from Message templates). */
class GstDocumentIssued extends Notification
{
    use Queueable;

    public function __construct(public Invoice|CreditNote $doc) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled($this->doc instanceof Invoice ? 'gst.invoice' : 'gst.credit_note') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->doc->booking;

        return Messages::mail($this->doc instanceof Invoice ? 'gst.invoice' : 'gst.credit_note', [
            'name' => $notifiable->name ?? '',
            'booking_code' => $booking->code,
            'number' => $this->doc->number,
            'amount' => number_format((float) $this->doc->total, 2),
        ], route('bookings.show', $booking))
            ->attachData(app(GstDocumentPdf::class)->render($this->doc), $this->doc->filename(), ['mime' => 'application/pdf']);
    }
}

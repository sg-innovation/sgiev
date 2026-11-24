<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\GstDocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** GST invoice and credit note PDFs (customer, operator staff, platform), plus public invoice verification. */
class InvoiceController extends Controller
{
    public function __construct(private readonly GstDocumentPdf $pdf) {}

    public function invoice(Request $request, Booking $booking): Response
    {
        Gate::authorize('view', $booking);
        $invoice = $booking->invoice;
        if (! $invoice) {
            return $request->user()->id === $booking->user_id
                ? to_route('bookings.show', $booking)->with('error', 'The tax invoice for this booking is not available yet.')
                : back()->with('error', 'No tax invoice has been issued for this booking.');
        }

        return $this->send($invoice, $request->boolean('download'));
    }

    /** Walk-in drivers have no account: their invoice is reached through a signed link (paid page or receipt QR). */
    public function walkIn(Request $request, \App\Models\Payment $payment): Response
    {
        $invoice = $payment->booking?->invoice;
        abort_unless($payment->status === \App\Models\Payment::SUCCESS && $payment->booking?->source === 'walk_in' && $invoice, 404);

        return $this->send($invoice, $request->boolean('download'));
    }

    public function creditNote(Request $request, CreditNote $creditNote): Response
    {
        Gate::authorize('view', $creditNote->booking);

        return $this->send($creditNote, $request->boolean('download'));
    }

    /** Public check that an invoice is genuine (link printed on the invoice). Shows no personal data. */
    public function verify(Request $request, Invoice $invoice): \Illuminate\Contracts\View\View
    {
        $valid = hash_equals(substr($invoice->content_hash, 0, 16), (string) $request->query('h'));

        return view('documents.verify', ['invoice' => $valid ? $invoice->load('company') : null]);
    }

    private function send(Invoice|CreditNote $doc, bool $download): Response
    {
        activity()->performedOn($doc->booking)->causedBy(auth()->user())->withProperties(['document' => $doc->number])->log('gst document downloaded');

        return response($this->pdf->render($doc), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$doc->filename().'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

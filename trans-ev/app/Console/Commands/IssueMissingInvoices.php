<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

/** Safety net: issue invoices for paid bookings and credit notes for successful refunds that have none yet. */
class IssueMissingInvoices extends Command
{
    protected $signature = 'invoices:issue-missing {--limit=500 : Maximum documents per run}';

    protected $description = 'Issue GST invoices and credit notes that are missing (paid bookings, successful refunds)';

    public function handle(InvoiceService $invoices): int
    {
        $limit = (int) $this->option('limit');
        $issued = 0;
        $skipped = 0;

        // Oldest payment first, so numbers follow the order money was received.
        Booking::query()
            ->whereDoesntHave('invoice')
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled])
            ->whereHas('payments', fn ($q) => $q->where('status', Payment::SUCCESS))
            ->with(['station.company', 'charger', 'connectorPoint'])
            ->get()
            ->sortBy(fn (Booking $b) => $b->payments->firstWhere('status', Payment::SUCCESS)?->paid_at)
            ->take($limit)
            ->each(function (Booking $b) use ($invoices, &$issued, &$skipped) {
                $invoices->issueForBooking($b) ? $issued++ : $skipped++;
            });

        $notes = 0;
        Refund::query()->where('status', Refund::SUCCESS)->whereNotIn('id', fn ($q) => $q->select('refund_id')->from('credit_notes')->whereNotNull('refund_id'))
            ->orderBy('refunded_at')->limit($limit)->get()
            ->each(function (Refund $r) use ($invoices, &$notes) {
                $invoices->creditNoteForRefund($r) && $notes++;
            });

        $this->info("Invoices issued: {$issued}; credit notes issued: {$notes}; skipped (no GSTIN in station state): {$skipped}.");

        return self::SUCCESS;
    }
}

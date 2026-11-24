<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Station;
use App\Notifications\GstDocumentIssued;
use App\Support\IndianNumber;
use App\Support\IndianStates;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues GST tax invoices when a booking is paid and credit notes when money is refunded.
 *
 * Time of supply for services is the earlier of invoice date and payment, so the invoice is issued on payment
 * (the customer pays up front for the charging slot). Refunds reduce the supply through a credit note (s.34).
 * Each supplier GSTIN has its own gapless series per financial year: INV "TEV/2627/000001", CRN "TEVC/2627/00001"
 * (Rule 46(b): at most 16 characters, unique for the financial year).
 */
class InvoiceService
{
    public function __construct(private readonly GstService $gst) {}

    public static function financialYear(CarbonInterface $at): string
    {
        $local = $at->copy()->setTimezone(Station::TIMEZONE);
        $start = $local->month >= 4 ? $local->year : $local->year - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
    }

    /** Issue the invoice for a paid booking (idempotent). Returns null when the operator has no GSTIN in the station's state. */
    public function issueForBooking(Booking $booking, ?Payment $payment = null): ?Invoice
    {
        if ($existing = Invoice::where('booking_id', $booking->id)->first()) {
            return $existing;
        }
        $booking->loadMissing(['station.company', 'charger', 'connectorPoint']);
        $station = $booking->station;
        $gstin = $this->gst->invoiceGstin($station);
        if (! $gstin) {
            Log::warning('Invoice not issued: no GSTIN registered in the station state', ['booking' => $booking->code, 'station' => $station->code]);

            return null;
        }
        $payment ??= $booking->payments()->where('status', Payment::SUCCESS)->where('purpose', 'booking')->latest('paid_at')->first();

        $invoice = DB::transaction(function () use ($booking, $station, $gstin, $payment) {
            if ($existing = Invoice::where('booking_id', $booking->id)->lockForUpdate()->first()) {
                return $existing;
            }
            $now = now();
            $fy = self::financialYear($now);
            $seq = $this->nextNumber($gstin, $fy, 'INV');
            $company = $station->company;
            $customer = $booking->customer_snapshot ?? [];
            $recipientGstin = filled($customer['gstin'] ?? null) ? strtoupper($customer['gstin']) : null;
            $rate = (string) $booking->gst_rate;
            $subtotal = (string) ($booking->subtotal_amount ?? bcadd((string) $booking->energy_amount, (string) $booking->session_fee, 2));

            $lines = filled($booking->price_lines) ? collect($booking->price_lines)->map(fn ($l) => [
                'description' => match ($l['type'] ?? '') {
                    'energy' => 'EV charging service · '.($l['label'] ?? 'Energy').' (pre-paid estimate)',
                    'parking' => 'Parking during charging',
                    default => $l['label'] ?? 'Service',
                },
                'sac' => $l['sac'] ?? $booking->sac_code, 'qty' => (string) $l['qty'], 'unit' => $l['unit'] ?? 'Nos',
                'rate' => (string) $l['rate'], 'amount' => (string) $l['amount'],
            ])->values()->all() : [
                [
                    'description' => 'EV charging service (energy, pre-paid estimate)',
                    'sac' => $booking->sac_code, 'qty' => (string) $booking->est_kwh, 'unit' => 'kWh',
                    'rate' => (string) $booking->rate_per_kwh, 'amount' => (string) $booking->energy_amount,
                ],
            ];
            if (blank($booking->price_lines) && bccomp((string) $booking->session_fee, '0', 2) > 0) {
                $lines[] = ['description' => 'Booking / session fee', 'sac' => $booking->sac_code, 'qty' => '1', 'unit' => 'Nos', 'rate' => (string) $booking->session_fee, 'amount' => (string) $booking->session_fee];
            }

            $data = [
                'company_id' => $company->id, 'booking_id' => $booking->id, 'payment_id' => $payment?->id,
                'station_id' => $station->id, 'user_id' => $booking->user_id,
                'number' => $this->format($company->invoice_prefix, $fy, $seq, 'INV'), 'sequence' => $seq, 'financial_year' => $fy,
                'issued_at' => $now, 'supply_type' => $recipientGstin ? 'B2B' : 'B2C',
                'supplier_gstin' => $gstin, 'supplier_state_code' => substr($gstin, 0, 2),
                'recipient_gstin' => $recipientGstin, 'place_of_supply' => (string) $booking->place_of_supply, 'reverse_charge' => false,
                'supplier' => [
                    'legal_name' => $company->legal_name, 'trade_name' => $company->trade_name, 'pan' => $company->pan, 'cin' => $company->cin,
                    'address' => collect([$company->address_line1, $company->address_line2, "{$company->city}, {$company->state} {$company->pincode}"])->filter()->implode(', '),
                    'state' => IndianStates::name(substr($gstin, 0, 2)), 'email' => $company->support_email ?: $company->email,
                    'phone' => $company->support_phone ?: $company->phone, 'terms' => $company->invoice_terms,
                ],
                'recipient' => [
                    'name' => $customer['name'] ?? $booking->user?->name, 'email' => $customer['email'] ?? null, 'phone' => $customer['phone'] ?? null,
                    'gstin' => $recipientGstin, 'state_code' => $customer['state_code'] ?? null,
                    'state' => IndianStates::name((string) ($customer['state_code'] ?? '')),
                ],
                'site' => [
                    'station' => $station->name, 'station_code' => $station->code,
                    'address' => collect([$station->address_line1, $station->address_line2, "{$station->city}, {$station->state} {$station->pincode}"])->filter()->implode(', '),
                    'charge_point_id' => $booking->charger->ocpp_identity ?: $booking->charger->code,
                    'charger' => "{$booking->charger->code} · {$booking->charger->type->value} ".rtrim(rtrim((string) $booking->charger->power_kw, '0'), '.').' kW',
                    'electrical' => $booking->charger->electricalSummary(),
                    'meter' => $booking->charger->meter_serial ? $booking->charger->meter_serial.($booking->charger->meter_accuracy_class ? ' · '.$booking->charger->meter_accuracy_class : '') : null,
                    'connector' => $booking->connectorPoint ? '#'.$booking->connectorPoint->connector_no.' '.$booking->connectorPoint->type->getLabel() : $booking->connector,
                    'booking_code' => $booking->code,
                    'slot' => $booking->start_at->setTimezone(Station::TIMEZONE)->format('d M Y, h:i A').' – '.$booking->end_at->setTimezone(Station::TIMEZONE)->format('h:i A'),
                    'vehicle' => $booking->vehicle_snapshot['registration_no'] ?? null,
                    'coupon' => $booking->coupon_code,
                    'payment_mode' => $payment?->payment_mode, 'payment_ref' => $payment?->gateway_txn_id, 'rrn' => $payment?->bank_rrn,
                    'paid_at' => $payment?->paid_at?->setTimezone(Station::TIMEZONE)->format('d M Y, h:i A'),
                ],
                'lines' => $lines, 'sac_code' => $booking->sac_code,
                'subtotal' => $subtotal, 'discount' => (string) ($booking->discount_amount ?? '0.00'),
                'taxable_amount' => (string) $booking->taxable_amount, 'gst_rate' => $rate,
                'cgst' => (string) $booking->cgst_amount, 'sgst' => (string) $booking->sgst_amount, 'igst' => (string) $booking->igst_amount,
                'total_tax' => bcadd(bcadd((string) $booking->cgst_amount, (string) $booking->sgst_amount, 2), (string) $booking->igst_amount, 2),
                'round_off' => '0.00', 'total' => (string) $booking->total_amount,
                'amount_in_words' => IndianNumber::rupeesInWords((string) $booking->total_amount),
            ];
            $data['content_hash'] = self::hash($data);

            $invoice = Invoice::create($data);
            activity()->performedOn($booking)->withProperties(['invoice' => $invoice->number, 'total' => $invoice->total])->log('invoice issued');

            return $invoice;
        });

        DB::afterCommit(fn () => $this->notify($invoice));

        return $invoice;
    }

    /** Credit note for a successful refund (idempotent). GST is reversed in proportion to the refunded amount. */
    public function creditNoteForRefund(Refund $refund): ?CreditNote
    {
        if ($refund->status !== Refund::SUCCESS || bccomp((string) $refund->amount, '0', 2) <= 0) {
            return null;
        }
        if ($existing = CreditNote::where('refund_id', $refund->id)->where('note_type', 'credit')->first()) {
            return $existing;
        }
        $invoice = Invoice::where('booking_id', $refund->booking_id)->first()
            ?? $this->issueForBooking($refund->booking()->first());
        if (! $invoice) {
            return null;
        }

        $note = DB::transaction(function () use ($refund, $invoice) {
            if ($existing = CreditNote::where('refund_id', $refund->id)->lockForUpdate()->first()) {
                return $existing;
            }
            // Never credit more than remains on the invoice.
            $remaining = bcsub(bcadd((string) $invoice->total, (string) CreditNote::where('invoice_id', $invoice->id)->where('note_type', 'debit')->sum('total'), 2),
                (string) CreditNote::where('invoice_id', $invoice->id)->where('note_type', 'credit')->sum('total'), 2);
            $gross = bccomp((string) $refund->amount, $remaining, 2) > 0 ? $remaining : (string) $refund->amount;
            if (bccomp($gross, '0', 2) <= 0) {
                return null;
            }
            $split = $this->splitGross($gross, (string) $invoice->gst_rate, (float) $invoice->igst > 0);
            $now = now();
            $fy = self::financialYear($now);
            $seq = $this->nextNumber($invoice->supplier_gstin, $fy, 'CRN');

            $data = [
                'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'refund_id' => $refund->id, 'booking_id' => $invoice->booking_id,
                'number' => $this->format($invoice->company->invoice_prefix, $fy, $seq, 'CRN'), 'sequence' => $seq, 'financial_year' => $fy,
                'issued_at' => $now, 'supplier_gstin' => $invoice->supplier_gstin, 'recipient_gstin' => $invoice->recipient_gstin,
                'place_of_supply' => $invoice->place_of_supply,
                'reason' => mb_substr('Refund: '.$refund->reason, 0, 255),
                'taxable_amount' => $split['taxable'], 'gst_rate' => (string) $invoice->gst_rate,
                'cgst' => $split['cgst'], 'sgst' => $split['sgst'], 'igst' => $split['igst'],
                'total_tax' => $split['tax'], 'total' => $gross,
                'amount_in_words' => IndianNumber::rupeesInWords($gross),
            ];
            $data['content_hash'] = self::hash($data);
            $note = CreditNote::create($data);
            activity()->performedOn($invoice->booking)->withProperties(['credit_note' => $note->number, 'total' => $note->total])->log('credit note issued');

            return $note;
        });

        if ($note) {
            DB::afterCommit(fn () => $this->notify($note));
        }

        return $note;
    }

    /** GST debit note (s.34(3), Rule 53) for a balance collected after charging delivered more than was prepaid. Idempotent. */
    public function debitNoteForPayment(Payment $payment, string $reason): ?CreditNote
    {
        if ($payment->status !== Payment::SUCCESS || bccomp((string) ($payment->paid_amount ?? $payment->amount), '0', 2) <= 0) {
            return null;
        }
        if ($existing = CreditNote::where('payment_id', $payment->id)->first()) {
            return $existing;
        }
        $invoice = Invoice::where('booking_id', $payment->booking_id)->first();
        if (! $invoice) {
            return null;
        }
        $note = DB::transaction(function () use ($payment, $invoice, $reason) {
            if ($existing = CreditNote::where('payment_id', $payment->id)->lockForUpdate()->first()) {
                return $existing;
            }
            $gross = (string) ($payment->paid_amount ?? $payment->amount);
            $split = $this->splitGross($gross, (string) $invoice->gst_rate, (float) $invoice->igst > 0);
            $now = now();
            $fy = self::financialYear($now);
            $seq = $this->nextNumber($invoice->supplier_gstin, $fy, 'DBN');
            $data = [
                'note_type' => 'debit', 'company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                'booking_id' => $invoice->booking_id, 'number' => $this->format($invoice->company->invoice_prefix, $fy, $seq, 'DBN'), 'sequence' => $seq,
                'financial_year' => $fy, 'issued_at' => $now, 'supplier_gstin' => $invoice->supplier_gstin, 'recipient_gstin' => $invoice->recipient_gstin,
                'place_of_supply' => $invoice->place_of_supply, 'reason' => mb_substr($reason, 0, 255),
                'taxable_amount' => $split['taxable'], 'gst_rate' => (string) $invoice->gst_rate,
                'cgst' => $split['cgst'], 'sgst' => $split['sgst'], 'igst' => $split['igst'], 'total_tax' => $split['tax'], 'total' => $gross,
                'amount_in_words' => IndianNumber::rupeesInWords($gross),
            ];
            $data['content_hash'] = self::hash($data);
            $note = CreditNote::create($data);
            activity()->performedOn($invoice->booking)->withProperties(['debit_note' => $note->number, 'total' => $note->total])->log('debit note issued');

            return $note;
        });
        if ($note) {
            DB::afterCommit(fn () => $this->notify($note));
        }

        return $note;
    }

    /** Split a GST-inclusive amount into taxable value and tax (CGST/SGST halves or IGST). */
    public function splitGross(string $gross, string $rate, bool $igst): array
    {
        $taxable = GstService::round(bcdiv(bcmul($gross, '100', 6), bcadd('100', $rate, 6), 6));
        $tax = bcsub($gross, $taxable, 2);
        if ($igst) {
            return ['taxable' => $taxable, 'tax' => $tax, 'cgst' => '0.00', 'sgst' => '0.00', 'igst' => $tax];
        }
        $cgst = GstService::round(bcdiv($tax, '2', 6));

        return ['taxable' => $taxable, 'tax' => $tax, 'cgst' => $cgst, 'sgst' => bcsub($tax, $cgst, 2), 'igst' => '0.00'];
    }

    /** Next number in a gapless series, under a row lock (must run inside a transaction). */
    public function nextNumber(string $gstin, string $fy, string $type): int
    {
        DB::table('document_sequences')->insertOrIgnore([
            'gstin' => $gstin, 'financial_year' => $fy, 'doc_type' => $type, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('document_sequences')->where(['gstin' => $gstin, 'financial_year' => $fy, 'doc_type' => $type])->lockForUpdate()->first();
        $next = $row->last_number + 1;
        DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }

    /** "TEV/2627/000123" (invoice) or "TEVC/2627/00012" (credit note): at most 16 characters (Rule 46(b)). */
    private function format(?string $prefix, string $fy, int $seq, string $type): string
    {
        $p = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix)) ?: 'INV', 0, 4);
        $years = substr($fy, 2, 2).substr($fy, 5, 2);

        return in_array($type, ['CRN', 'DBN'], true)
            ? substr($p, 0, 3).($type === 'CRN' ? 'C' : 'D').'/'.$years.'/'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT)
            : $p.'/'.$years.'/'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    public static function hash(array $data): string
    {
        $canon = collect($data)->except(['content_hash'])->map(fn ($v) => $v instanceof CarbonInterface ? $v->copy()->utc()->format('Y-m-d H:i:s') : $v)->sortKeys()->all();

        return hash('sha256', json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function notify(Invoice|CreditNote $doc): void
    {
        try {
            $user = $doc->booking?->user;
            // Seeded demo bookings are never emailed.
            if ($user && empty($doc->booking->customer_snapshot['demo'])) {
                $user->notify(new GstDocumentIssued($doc));
                $doc->forceFill(['emailed_at' => now()])->save();
            } elseif (! $user && ($email = $doc->booking?->customer_snapshot['email'] ?? null) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                \Illuminate\Support\Facades\Notification::route('mail', $email)->notify(new GstDocumentIssued($doc));
                $doc->forceFill(['emailed_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            Log::error('GST document email failed', ['number' => $doc->number, 'error' => $e->getMessage()]);
        }
    }
}

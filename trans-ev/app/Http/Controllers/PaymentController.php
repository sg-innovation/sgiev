<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Customer clicks "Pay": create a SabPaisa session and leave the site for the hosted checkout. */
    public function start(Request $request, Booking $booking): SymfonyResponse
    {
        Gate::authorize('pay', $booking);
        $url = $this->payments->start($booking);

        // The page navigates itself (a plain browser redirect keeps the one-time confirmation message on return).
        return $request->expectsJson() ? response()->json(['url' => $url]) : redirect()->away($url);
    }

    /** Browser comes back from SabPaisa. Status is confirmed server-side by enquiry before anything changes. */
    public function return(Request $request, Payment $payment): RedirectResponse
    {
        $payment = $this->payments->handleReturn($payment, $request->query(), $request->ip());
        if ($payment->purpose === 'wallet') {
            if ($request->user()?->id !== $payment->user_id) {
                return redirect()->route('login');
            }
            [$level, $text] = match ($payment->status) {
                Payment::SUCCESS => ['success', 'Wallet recharged with ₹'.number_format((float) ($payment->paid_amount ?? $payment->amount), 2).'.'],
                Payment::FAILED => ['error', 'The recharge was not completed. No money was taken.'],
                default => ['success', 'We are confirming your recharge with the bank. Your balance will update shortly.'],
            };

            return redirect(\App\Filament\Pages\WalletPage::getUrl(panel: 'admin'))->with($level, $text);
        }
        $booking = $payment->booking;

        $message = match (true) {
            $payment->status === Payment::SUCCESS && $booking->status->value === 'confirmed' => ['success', 'Payment successful. Your booking is confirmed.'],
            $payment->status === Payment::SUCCESS => ['error', 'Payment received, but the slot was no longer available. A full refund has been started.'],
            $payment->status === Payment::FAILED => ['error', 'Payment was not completed. You can try again while your slot is held.'],
            default => ['success', 'We are confirming your payment with the bank. This page will update shortly.'],
        };

        // The return URL is public (the gateway redirects the browser), so only send the owner to their booking.
        if ($request->user()?->id !== $booking->user_id) {
            return redirect()->route('login');
        }

        return redirect()->route('bookings.show', $booking)->with($message[0], $message[1]);
    }

    /** SabPaisa webhook (one URL per operator, given to SabPaisa during onboarding). */
    public function webhook(Request $request, Company $company): Response
    {
        $ok = $this->payments->handleWebhook($company, $request->getContent(), $request->header('X-SabPaisa-Signature'), $request->ip());

        return response($ok ? 'OK' : 'Invalid signature', $ok ? 200 : 401);
    }

    /** Customer asks to re-check a pending payment. */
    public function refresh(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('view', $booking);
        $booking->payments()->where('status', Payment::PENDING)->get()->each(fn (Payment $p) => $this->payments->reconcile($p));

        return back();
    }

    /** Printable payment receipt. */
    public function receipt(Request $request, Booking $booking): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        Gate::authorize('view', $booking);
        $payment = $booking->payments()->where('status', Payment::SUCCESS)->latest('paid_at')->first();
        if (! $payment) {
            // No successful online payment, so there is nothing to give a receipt for.
            $message = 'There is no payment receipt for this booking because no online payment was recorded.';

            return $request->user()->id === $booking->user_id
                ? to_route('bookings.show', $booking)->with('error', $message)
                : redirect(\App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $booking]))->with('error', $message);
        }
        $booking->load(['station.company', 'charger', 'connectorPoint', 'refunds']);
        $station = $booking->station;
        $charger = $booking->charger;
        $point = $booking->connectorPoint;
        $company = $booking->station->company;
        $tz = \App\Models\Station::TIMEZONE;

        return Inertia::render('booking/receipt', [
            'receipt' => [
                'number' => 'RCPT-'.$payment->merchant_txn_id,
                'date' => $payment->paid_at->setTimezone($tz)->format('j M Y, g:i A'),
                'operator' => [
                    'legalName' => $company->legal_name,
                    'tradeName' => $company->trade_name,
                    'address' => collect([$company->address_line1, $company->address_line2, "{$company->city}, {$company->state} {$company->pincode}"])->filter()->implode(', '),
                    'gstin' => app(\App\Services\GstService::class)->invoiceGstin($booking->station) ?? $company->gstin,
                    'support' => $company->support_email ?: $company->email,
                ],
                'customer' => $booking->customer_snapshot,
                'booking' => \App\Http\Resources\BookingPresenter::detail($booking),
                'payment' => [
                    'amount' => (float) ($payment->paid_amount ?? $payment->amount),
                    'mode' => $payment->payment_mode,
                    'gatewayTxnId' => $payment->gateway_txn_id,
                    'bankTxnId' => $payment->bank_txn_id,
                    'merchantTxnId' => $payment->merchant_txn_id,
                    'environment' => $payment->environment,
                    'rrn' => $payment->bank_rrn,
                    'paidAt' => $payment->paid_at->setTimezone($tz)->format('j M Y, g:i:s A'),
                    'amountInWords' => \App\Support\IndianNumber::rupeesInWords((string) ($payment->paid_amount ?? $payment->amount)),
                ],
                // Charging point ("terminal") the session is booked on.
                'chargePoint' => [
                    'station' => $station->name,
                    'stationCode' => $station->code,
                    'address' => collect([$station->address_line1, $station->address_line2, "{$station->city}, {$station->state} {$station->pincode}"])->filter()->implode(', '),
                    'chargerCode' => $charger->code,
                    'chargerType' => $charger->type->value,
                    'powerKw' => (float) $charger->power_kw,
                    'serialNo' => $charger->serial_no,
                    'ocppId' => $charger->ocpp_identity,
                    'connectorNo' => $point?->connector_no,
                    'connectorType' => $point?->type->getLabel() ?? $booking->connector,
                    'electrical' => $charger->electricalSummary(),
                    'standard' => $charger->standard,
                    'meterSerial' => $charger->meter_serial,
                    'meterClass' => $charger->meter_accuracy_class,
                    'oem' => trim(($charger->oem ?? '').' '.($charger->model ?? '')) ?: null,
                ],
                'viewerIsStaff' => $request->user()->id !== $booking->user_id,
                'refunds' => $booking->refunds->map(fn ($r) => [
                    'amount' => (float) $r->amount, 'status' => $r->status, 'rule' => $r->policy_rule,
                    'date' => $r->created_at->setTimezone($tz)->format('j M Y'),
                ]),
            ],
        ]);
    }

    /** LOCAL SIMULATOR: test checkout page (only registered when simulation is enabled). */
    public function simulatorCheckout(Payment $payment): \Illuminate\Contracts\View\View
    {
        abort_unless(config('payments.sabpaisa.simulate') && $payment->environment === 'simulator' && $payment->status === Payment::PENDING, 404);

        return view('dev.sabpaisa-checkout', ['payment' => $payment->load('booking.station')]);
    }

    public function simulatorComplete(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless(config('payments.sabpaisa.simulate') && $payment->environment === 'simulator' && $payment->status === Payment::PENDING, 404);
        $status = $request->validate(['status' => ['required', Rule::in(['SUCCESS', 'FAILED', 'CANCELLED'])]])['status'];
        [$returnUrl, $query] = SimulatedSabPaisaGateway::complete($payment, $status);

        return redirect()->away($returnUrl.'?'.http_build_query($query));
    }
}

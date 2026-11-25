<?php

namespace App\Services\Payments;

use App\Models\Company;

/** Picks each company's payment gateway (SabPaisa or Razorpay, chosen on the company) or the local simulator. */
class GatewayFactory
{
    public const GATEWAYS = ['sabpaisa' => 'SabPaisa', 'razorpay' => 'Razorpay'];

    public function simulating(): bool
    {
        return (bool) config('payments.sabpaisa.simulate');
    }

    /** Whether customers can pay for this company's stations. */
    public function canAcceptPayments(?Company $company): bool
    {
        return $company !== null && ($this->simulating() || $company->hasPaymentSetup());
    }

    public function for(Company $company): PaymentGateway
    {
        if ($this->simulating()) {
            return new SimulatedSabPaisaGateway;
        }
        if (! $company->hasPaymentSetup()) {
            throw new GatewayException("{$company->trade_name} has not connected a payment gateway yet.");
        }

        return $this->make($company, $company->payment_gateway ?: 'sabpaisa');
    }

    /** Gateway that processed an existing payment (simulated payments stay simulated). */
    public function forPayment(\App\Models\Payment $payment): PaymentGateway
    {
        if ($payment->environment === 'simulator') {
            return new SimulatedSabPaisaGateway;
        }

        return $this->make($payment->company, $payment->gateway ?: 'sabpaisa');
    }

    private function make(Company $company, string $gateway): PaymentGateway
    {
        return match ($gateway) {
            'razorpay' => new RazorpayGateway($company),
            default => new SabPaisaGateway($company),
        };
    }
}

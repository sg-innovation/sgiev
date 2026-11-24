<?php

namespace App\Services\Payments;

use App\Models\Company;

class GatewayFactory
{
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
            throw new GatewayException("{$company->trade_name} has not connected a SabPaisa account yet.");
        }

        return new SabPaisaGateway($company);
    }

    /** Gateway that processed an existing payment (simulated payments stay simulated). */
    public function forPayment(\App\Models\Payment $payment): PaymentGateway
    {
        if ($payment->environment === 'simulator') {
            return new SimulatedSabPaisaGateway;
        }

        return new SabPaisaGateway($payment->company);
    }
}

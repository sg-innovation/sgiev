<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Support\Tenancy;

/** Payments are read-only records; refunds need payments.refund within the operator's own company. */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view') && Tenancy::owns($payment->company_id, $user);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->can('payments.refund') && Tenancy::owns($payment->company_id, $user) && $payment->status === Payment::SUCCESS;
    }

    public function reconcile(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment) && ! $payment->isFinal();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

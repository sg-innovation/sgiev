<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenancy;

/** Invoices are read-only for everyone (issued by the system); finance, owners and auditors can view their company's. */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('payments.view') && Tenancy::owns($invoice->company_id, $user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

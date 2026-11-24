<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;
use App\Support\Tenancy;

/** Credit notes are issued by the system when refunds succeed; read-only in admin. */
class CreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, CreditNote $note): bool
    {
        return $user->can('payments.view') && Tenancy::owns($note->company_id, $user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CreditNote $note): bool
    {
        return false;
    }

    public function delete(User $user, CreditNote $note): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

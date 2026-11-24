<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Plan;
use App\Models\Station;
use App\Models\User;
use App\Notifications\PartnerUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Partner (company) and station review workflow (SGI EV SRS §5.1: public only after admin approval). */
class ApprovalService
{
    /**
     * @param  array{plan_id?: ?int, commission_percent?: mixed, per_booking_fee?: mixed, fee_terms_note?: ?string}  $terms
     *         commission agreed at onboarding (null rate = use the plan's rate)
     */
    public function approveCompany(Company $company, User $by, array $terms = []): void
    {
        $missing = collect(CompanyDocument::required())->diff($company->documents()->where('status', '!=', 'rejected')->pluck('type'));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['company' => 'Missing KYC documents: '.$missing->map(fn ($t) => CompanyDocument::typeLabel($t))->implode(', ').'.']);
        }

        DB::transaction(function () use ($company, $by, $terms) {
            $company->documents()->where('status', 'pending')->update(['status' => 'verified', 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            $rate = fn ($v) => $v === null || $v === '' ? null : number_format((float) $v, 2, '.', '');
            $company->forceFill([
                'status' => 'active', 'review_note' => null, 'reviewed_at' => now(), 'reviewed_by' => $by->id,
                'onboarded_at' => $company->onboarded_at ?? now(),
                'plan_id' => $terms['plan_id'] ?? $company->plan_id ?? Plan::default()?->id,
            ] + (array_key_exists('commission_percent', $terms) || array_key_exists('per_booking_fee', $terms) ? [
                'commission_percent' => $rate($terms['commission_percent'] ?? null),
                'per_booking_fee' => $rate($terms['per_booking_fee'] ?? null),
                'billing_mode' => in_array($terms['billing_mode'] ?? null, ['wallet', 'invoice'], true) ? $terms['billing_mode'] : $company->billing_mode,
                'fee_terms_note' => filled($terms['fee_terms_note'] ?? null) ? mb_substr(strip_tags($terms['fee_terms_note']), 0, 500) : null,
                'fee_terms_agreed_at' => now(),
            ] : []))->save();
            activity()->performedOn($company)->causedBy($by)->withProperties(array_filter($terms, fn ($v) => $v !== null && $v !== ''))->log('company approved');
        });
        $this->notifyOwners($company, 'partner.approved', ['company' => $company->trade_name]);
    }

    public function requestCompanyChanges(Company $company, User $by, string $note): void
    {
        $company->forceFill(['status' => 'changes_requested', 'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
        activity()->performedOn($company)->causedBy($by)->withProperties(['note' => $note])->log('company changes requested');
        $this->notifyOwners($company, 'partner.changes_requested', ['company' => $company->trade_name, 'note' => $note]);
    }

    public function rejectCompany(Company $company, User $by, string $note): void
    {
        $company->forceFill(['status' => 'rejected', 'review_note' => $note, 'reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
        activity()->performedOn($company)->causedBy($by)->withProperties(['note' => $note])->log('company rejected');
        $this->notifyOwners($company, 'partner.rejected', ['company' => $company->trade_name, 'note' => $note]);
    }

    /** Owner re-submits after fixing requested changes. */
    public function resubmitCompany(Company $company, User $by): void
    {
        abort_unless($company->status === 'changes_requested', 422);
        $company->forceFill(['status' => 'pending', 'submitted_at' => now()])->save();
        activity()->performedOn($company)->causedBy($by)->log('company resubmitted');
    }

    public function submitStation(Station $station, User $by): void
    {
        if (! in_array($station->approval_status, ['draft', 'rejected'], true) && ! $station->needs_review) {
            throw ValidationException::withMessages(['station' => 'This station is already submitted or approved.']);
        }
        if ($station->chargers()->doesntExist() || $station->tariffs()->doesntExist()) {
            throw ValidationException::withMessages(['station' => 'Add at least one charger and a tariff before submitting.']);
        }
        if (! app(\App\Services\GstService::class)->invoiceGstin($station->loadMissing('company'))) {
            throw ValidationException::withMessages(['station' => 'Add the GSTIN registered in this station’s state (Station → Legal & contact) so customers can be given GST invoices.']);
        }
        $station->forceFill(['approval_status' => $station->approval_status === 'approved' ? 'approved' : 'submitted', 'submitted_at' => now()])->save();
        activity()->performedOn($station)->causedBy($by)->log('station submitted for approval');
    }

    public function approveStation(Station $station, User $by): void
    {
        if ($station->company?->status !== 'active') {
            throw ValidationException::withMessages(['station' => 'Approve the company first.']);
        }
        if (! app(\App\Services\GstService::class)->invoiceGstin($station->loadMissing('company'))) {
            throw ValidationException::withMessages(['station' => 'Add the GSTIN registered in this station’s state (Station → Legal & contact) so customers can be given GST invoices.']);
        }
        $station->forceFill(['approval_status' => 'approved', 'approval_note' => null, 'needs_review' => false, 'approved_at' => now(), 'approved_by' => $by->id])->save();
        activity()->performedOn($station)->causedBy($by)->log('station approved');
        $this->notifyOwners($station->company, 'station.approved', ['station' => $station->name]);
    }

    public function rejectStation(Station $station, User $by, string $note): void
    {
        $station->forceFill(['approval_status' => 'rejected', 'approval_note' => $note, 'needs_review' => false, 'approved_by' => $by->id])->save();
        activity()->performedOn($station)->causedBy($by)->withProperties(['note' => $note])->log('station rejected');
        $this->notifyOwners($station->company, 'station.rejected', ['station' => $station->name, 'note' => $note]);
    }

    private function notifyOwners(?Company $company, string $template, array $vars): void
    {
        if (! $company) {
            return;
        }
        $company->users()->role(\App\Enums\Role::CompanyOwner->value)->get()->each(fn (User $u) => $u->notify(new PartnerUpdate($template, $vars)));
    }
}

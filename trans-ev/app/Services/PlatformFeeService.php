<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PlatformFeeStatement;
use App\Models\Refund;
use App\Models\Station;
use App\Models\User;
use App\Support\BusinessRules;
use App\Support\IndianNumber;
use App\Support\IndianStates;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly platform-fee statements for operators.
 *
 * Basis: the operator's net charging sales for the month (taxable value of paid bookings, reduced in proportion
 * to anything refunded). Commission % and per-booking fee come from the company (if overridden) or its plan; the
 * plan's monthly price is billed as a subscription. Discounts funded by Trans EV (platform coupons) are credited
 * back to the operator as a separate reimbursement line, outside the fee invoice's taxable value.
 */
class PlatformFeeService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** Create or refresh the draft statement for a company and month (issued statements are never touched). */
    public function generate(Company $company, CarbonInterface $month): ?PlatformFeeStatement
    {
        if ($company->is_platform) {
            return null;
        }
        $from = Carbon::parse($month->format('Y-m-01'), Station::TIMEZONE)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $existing = PlatformFeeStatement::query()->where('company_id', $company->id)->whereDate('period_start', $from->toDateString())->first();
        if ($existing && $existing->status !== 'draft') {
            return $existing;
        }

        $company->loadMissing('plan');
        $plan = $company->plan ?? \App\Models\Plan::default();
        ['commission_percent' => $pct, 'per_booking_fee' => $perBooking] = $company->feeRates();
        $subscription = (string) ($plan?->price_monthly ?? '0');

        [$count, $sales, $reimburse] = $this->sales($company, $from, $to);
        $wallet = WalletService::usesWallet($company);
        if ($wallet) {
            $reimburse = '0.00'; // already credited to the wallet booking by booking
        }

        $commission = GstService::round(bcdiv(bcmul($sales, $pct, 6), '100', 6));
        $perBookingAmount = GstService::round(bcmul($perBooking, (string) $count, 6));
        $taxable = bcadd(bcadd($commission, $perBookingAmount, 2), $subscription, 2);

        $platform = Company::query()->where('is_platform', true)->firstOrFail();
        $supplierGstin = (string) $platform->gstin;
        $pos = $company->gstin ? substr($company->gstin, 0, 2) : $company->state_code;
        $rate = (string) BusinessRules::get('platform_fees.gst_rate');
        $tax = app(GstService::class)->split($taxable, $rate, substr($supplierGstin, 0, 2), (string) $pos);
        $total = bcadd($taxable, $tax['total_tax'], 2);
        $sac = (string) BusinessRules::get('platform_fees.sac');

        $lines = array_values(array_filter([
            bccomp($commission, '0', 2) > 0 ? ['description' => "Platform commission {$pct}% on net charging sales of ₹".number_format((float) $sales, 2), 'sac' => $sac, 'qty' => '1', 'unit' => 'Nos', 'rate' => $commission, 'amount' => $commission] : null,
            bccomp($perBookingAmount, '0', 2) > 0 ? ['description' => 'Platform fee per paid booking', 'sac' => $sac, 'qty' => (string) $count, 'unit' => 'Nos', 'rate' => $perBooking, 'amount' => $perBookingAmount] : null,
            bccomp($subscription, '0', 2) > 0 ? ['description' => ($plan?->name ?? 'Plan').' plan subscription · '.$from->format('F Y'), 'sac' => $sac, 'qty' => '1', 'unit' => 'Month', 'rate' => $subscription, 'amount' => $subscription] : null,
        ]));

        $data = [
            'company_id' => $company->id, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'status' => 'draft',
            'bookings_count' => $count, 'sales_taxable' => $sales, 'commission_percent' => $pct, 'commission_amount' => $commission,
            'per_booking_fee' => $perBooking, 'per_booking_amount' => $perBookingAmount, 'subscription_amount' => $subscription,
            'taxable_amount' => $taxable, 'gst_rate' => $rate, 'cgst' => $tax['cgst'], 'sgst' => $tax['sgst'], 'igst' => $tax['igst'],
            'total_tax' => $tax['total_tax'], 'total' => $total, 'reimbursement_amount' => $reimburse, 'net_payable' => bcsub($total, $reimburse, 2),
            'lines' => $lines, 'sac_code' => $sac, 'supplier_gstin' => $supplierGstin ?: null, 'recipient_gstin' => $company->gstin, 'place_of_supply' => $pos,
            'supplier' => [
                'legal_name' => $platform->legal_name, 'trade_name' => $platform->trade_name, 'pan' => $platform->pan,
                'address' => collect([$platform->address_line1, $platform->address_line2, "{$platform->city}, {$platform->state} {$platform->pincode}"])->filter()->implode(', '),
                'state' => IndianStates::name(substr($supplierGstin, 0, 2)), 'email' => $platform->support_email ?: $platform->email,
            ],
            'recipient' => [
                'legal_name' => $company->legal_name, 'trade_name' => $company->trade_name, 'gstin' => $company->gstin,
                'address' => collect([$company->address_line1, $company->address_line2, "{$company->city}, {$company->state} {$company->pincode}"])->filter()->implode(', '),
                'state' => IndianStates::name((string) $pos), 'email' => $company->email,
            ],
        ];

        return $existing ? tap($existing)->update($data) : PlatformFeeStatement::create($data);
    }

    /** @return array{0:int, 1:string, 2:string} [paid bookings, net taxable sales, platform-funded discounts to reimburse] */
    public function sales(Company $company, CarbonInterface $from, CarbonInterface $to): array
    {
        $paidStatuses = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled];
        $bookings = Booking::query()->where('company_id', $company->id)->whereIn('status', $paidStatuses)
            ->whereHas('payments', fn ($q) => $q->where('status', Payment::SUCCESS)->whereBetween('paid_at', [$from->copy()->utc(), $to->copy()->utc()]))
            ->with('refunds')->get();

        $count = 0;
        $sales = '0.00';
        $reimburse = '0.00';
        foreach ($bookings as $b) {
            $total = (string) $b->total_amount;
            if (bccomp($total, '0', 2) <= 0) {
                continue;
            }
            $refunded = (string) $b->refunds->whereIn('status', [Refund::SUCCESS, Refund::INITIATED])->sum('amount');
            $kept = bcdiv(bcsub($total, $refunded, 6), $total, 6); // share of the sale the operator kept
            if (bccomp($kept, '0', 6) <= 0) {
                continue;
            }
            $count++;
            $sales = bcadd($sales, GstService::round(bcmul((string) $b->taxable_amount, $kept, 6)), 2);
            if ($b->discount_funded_by === 'platform' && bccomp((string) $b->discount_amount, '0', 2) > 0) {
                $reimburse = bcadd($reimburse, GstService::round(bcmul((string) $b->discount_amount, $kept, 6)), 2);
            }
        }

        return [$count, $sales, $reimburse];
    }

    /** @return Collection<int, PlatformFeeStatement> drafts for every operator for the month */
    public function generateAll(CarbonInterface $month): Collection
    {
        return Company::query()->where('is_platform', false)->whereIn('status', ['active', 'suspended'])->get()
            ->map(fn (Company $c) => $this->generate($c, $month))->filter()->values();
    }

    /** Issue a draft as a numbered Trans EV tax invoice and email the operator. */
    public function issue(PlatformFeeStatement $s, User $by): PlatformFeeStatement
    {
        if ($s->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only drafts can be issued.']);
        }
        if (! $s->supplier_gstin) {
            throw ValidationException::withMessages(['status' => 'Add the Trans EV GSTIN on the platform company before issuing.']);
        }
        if (bccomp((string) $s->total, '0', 2) <= 0 && bccomp((string) $s->reimbursement_amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['status' => 'Nothing to bill for this month. Void the draft instead.']);
        }

        DB::transaction(function () use ($s, $by) {
            $now = now();
            $fy = InvoiceService::financialYear($now);
            $seq = $this->invoices->nextNumber($s->supplier_gstin, $fy, 'PLF');
            $prefix = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) Company::query()->where('is_platform', true)->value('invoice_prefix'))) ?: 'TEV', 0, 3);
            $s->forceFill([
                'status' => 'issued', 'number' => $prefix.'P/'.substr($fy, 2, 2).substr($fy, 5, 2).'/'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'sequence' => $seq, 'financial_year' => $fy, 'issued_at' => $now, 'issued_by' => $by->id,
                'due_on' => $now->copy()->setTimezone(Station::TIMEZONE)->addDays(BusinessRules::int('platform_fees.payment_days'))->toDateString(),
            ]);
            if (WalletService::usesWallet($s->company)) {
                // Per-booking fees were already taken from the wallet when customers paid; only the subscription is debited now.
                $sub = (string) $s->subscription_amount;
                if (bccomp($sub, '0', 2) > 0) {
                    $withGst = bcadd($sub, GstService::round(bcdiv(bcmul($sub, (string) $s->gst_rate, 6), '100', 6)), 2);
                    app(WalletService::class)->post($s->company, 'subscription', bcmul($withGst, '-1', 2), "Plan subscription {$s->periodLabel()} · {$s->number}", ['statement_id' => $s->id, 'created_by' => $by->id]);
                }
                $s->forceFill(['status' => 'paid', 'paid_amount' => $s->total, 'paid_at' => now(), 'payment_reference' => 'Prepaid wallet', 'net_payable' => '0.00']);
            }
            $s->content_hash = InvoiceService::hash($s->only(['number', 'company_id', 'period_start', 'taxable_amount', 'cgst', 'sgst', 'igst', 'total', 'reimbursement_amount', 'lines', 'supplier_gstin', 'recipient_gstin']));
            $s->save();
        });

        DB::afterCommit(fn () => $s->company->users()->role(\App\Enums\Role::CompanyOwner->value)->get()
            ->each(fn (User $u) => $u->notify(new \App\Notifications\PlatformFeeIssued($s->fresh()))));

        return $s->fresh();
    }

    public function recordPayment(PlatformFeeStatement $s, string $amount, string $reference, User $by): void
    {
        if (! in_array($s->status, ['issued', 'paid'], true)) {
            throw ValidationException::withMessages(['status' => 'Issue the statement before recording a payment.']);
        }
        $paid = bcadd((string) ($s->paid_amount ?? '0'), $amount, 2);
        $s->forceFill([
            'paid_amount' => $paid, 'payment_reference' => trim(($s->payment_reference ? $s->payment_reference.'; ' : '').$reference),
            'paid_at' => now(), 'status' => bccomp($paid, (string) $s->net_payable, 2) >= 0 ? 'paid' : 'issued',
        ])->save();
        activity()->performedOn($s)->causedBy($by)->withProperties(['amount' => $amount, 'reference' => $reference])->log('platform fee payment recorded');
    }

    public function void(PlatformFeeStatement $s, User $by, string $reason): void
    {
        if ($s->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Issued invoices cannot be voided; issue a credit note instead.']);
        }
        $s->forceFill(['status' => 'void', 'notes' => mb_substr($reason, 0, 500)])->save();
        activity()->performedOn($s)->causedBy($by)->withProperties(['reason' => $reason])->log('platform fee statement voided');
    }

    public static function amountInWords(PlatformFeeStatement $s): string
    {
        return IndianNumber::rupeesInWords((string) $s->total);
    }
}

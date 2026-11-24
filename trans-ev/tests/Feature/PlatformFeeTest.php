<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\PlatformFees\Pages\ListPlatformFees;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformFeeStatement;
use App\Models\Station;
use App\Models\User;
use App\Notifications\PlatformFeeIssued;
use App\Services\PlatformFeeService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** B7: monthly platform fees billed to operators, with commission negotiated at onboarding. */
class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private Company $gg;

    private Station $station;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->gg = Company::where('slug', 'greengrid-demo')->first();
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::SuperAdmin->value);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A paid booking for GreenGrid with a given taxable value (paid on the given date). */
    private function paid(string $taxable, string $paidAt = '2026-11-10 12:00', array $over = []): Booking
    {
        $total = bcmul($taxable, '1.18', 2);
        $b = Booking::factory()->forStation($this->station, User::factory()->create())->create(array_merge([
            'status' => BookingStatus::Completed, 'taxable_amount' => $taxable, 'total_amount' => $total, 'confirmed_at' => Carbon::parse($paidAt, 'Asia/Kolkata'),
        ], $over));
        $b->forceFill(['created_at' => Carbon::parse($paidAt, 'Asia/Kolkata')])->save();
        DemoActivitySeeder::demoPayment($b);
        $b->payments()->update(['paid_at' => Carbon::parse($paidAt, 'Asia/Kolkata')->utc()]);

        return $b->fresh();
    }

    public function test_statement_uses_plan_commission_and_subscription(): void
    {
        $this->paid('1000.00');
        $this->paid('500.00');
        $this->paid('999.00', '2026-10-31 23:00'); // previous month: excluded
        $plan = $this->gg->plan;
        $plan->update(['commission_percent' => 5, 'per_booking_fee' => 2, 'price_monthly' => 499]);

        $s = app(PlatformFeeService::class)->generate($this->gg->fresh(), Carbon::parse('2026-11-01'));
        $this->assertSame(2, $s->bookings_count);
        $this->assertSame('1500.00', (string) $s->sales_taxable);
        $this->assertSame('75.00', (string) $s->commission_amount);
        $this->assertSame('4.00', (string) $s->per_booking_amount);
        $this->assertSame('499.00', (string) $s->subscription_amount);
        $this->assertSame('578.00', (string) $s->taxable_amount);
        // Trans EV (Bihar) → GreenGrid (Maharashtra): inter-state, IGST 18%.
        $this->assertSame('104.04', (string) $s->igst);
        $this->assertSame('682.04', (string) $s->total);
        $this->assertSame('draft', $s->status);
    }

    public function test_negotiated_commission_overrides_the_plan(): void
    {
        $this->paid('1000.00');
        $this->gg->update(['commission_percent' => 2, 'per_booking_fee' => 0]);
        $this->assertNotNull($this->gg->fresh()->fee_terms_agreed_at);
        $s = app(PlatformFeeService::class)->generate($this->gg->fresh(), Carbon::parse('2026-11-01'));
        $this->assertSame('2.00', (string) $s->commission_percent);
        $this->assertSame('20.00', (string) $s->commission_amount);
    }

    public function test_refunds_reduce_sales_and_platform_coupons_are_reimbursed(): void
    {
        $coupon = Coupon::create(['code' => 'PLAT50', 'name' => 'x', 'type' => 'flat', 'value' => 50, 'is_active' => true]);
        $b = $this->paid('1000.00', '2026-11-10 12:00', ['coupon_id' => $coupon->id, 'coupon_code' => 'PLAT50', 'discount_amount' => '50.00', 'discount_funded_by' => 'platform']);
        $payment = $b->payments()->first();
        \App\Models\Refund::create(['company_id' => $this->gg->id, 'payment_id' => $payment->id, 'booking_id' => $b->id, 'amount' => bcdiv((string) $b->total_amount, '2', 2), 'reason' => 'half', 'status' => 'success']);

        $s = app(PlatformFeeService::class)->generate($this->gg->fresh(), Carbon::parse('2026-11-01'));
        $this->assertSame('500.00', (string) $s->sales_taxable, 'half was refunded');
        $this->assertSame('25.00', (string) $s->reimbursement_amount, 'platform coupon credited in proportion');
        $this->assertSame(bcsub((string) $s->total, '25.00', 2), (string) $s->net_payable);
    }

    public function test_issue_numbers_locks_and_emails(): void
    {
        $this->paid('1000.00');
        $owner = User::where('email', 'owner@greengrid.test')->first();
        $svc = app(PlatformFeeService::class);
        $s = $svc->generate($this->gg->fresh(), Carbon::parse('2026-11-01'));
        $s = $svc->issue($s, $this->admin);

        $this->assertSame('issued', $s->status);
        $this->assertSame('TEVP/2627/00001', $s->number);
        $this->assertNotNull($s->due_on);
        Notification::assertSentTo($owner, PlatformFeeIssued::class);

        // Locked: regenerating does not change it, and amounts cannot be edited.
        $this->paid('5000.00');
        $this->assertSame((string) $s->total, (string) $svc->generate($this->gg->fresh(), Carbon::parse('2026-11-01'))->total);
        $this->expectException(\LogicException::class);
        $s->update(['total' => 1]);
    }

    public function test_payment_marks_paid(): void
    {
        $this->paid('1000.00');
        $svc = app(PlatformFeeService::class);
        $s = $svc->issue($svc->generate($this->gg->fresh(), Carbon::parse('2026-11-01')), $this->admin);
        $svc->recordPayment($s, (string) $s->net_payable, 'UTR123', $this->admin);
        $this->assertSame('paid', $s->fresh()->status);
    }

    public function test_operators_see_only_their_issued_statements(): void
    {
        $this->paid('1000.00');
        $svc = app(PlatformFeeService::class);
        $draft = $svc->generate($this->gg->fresh(), Carbon::parse('2026-11-01'));
        $owner = User::where('email', 'owner@greengrid.test')->first();

        $this->actingAs($owner);
        Livewire::test(ListPlatformFees::class)->assertCanNotSeeTableRecords([$draft]);
        $this->get(route('platform-fees.pdf', $draft))->assertNotFound();

        $issued = $svc->issue($draft, $this->admin);
        Livewire::test(ListPlatformFees::class)->assertCanSeeTableRecords([$issued])->assertTableActionHidden('issue', $issued);
        $this->get(route('platform-fees.pdf', $issued))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_commission_is_agreed_when_approving_a_partner(): void
    {
        $this->gg->forceFill(['status' => 'pending'])->save();
        foreach (\App\Models\CompanyDocument::required() as $type) {
            \App\Models\CompanyDocument::create(['company_id' => $this->gg->id, 'type' => $type, 'disk' => 'local', 'path' => "kyc/x/{$type}.pdf", 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64)]);
        }
        $this->actingAs($this->admin);
        Livewire::test(ListCompanies::class)
            ->callTableAction('approve', $this->gg, data: ['plan_id' => Plan::where('slug', 'premium')->value('id'), 'commission_percent' => '1.5', 'per_booking_fee' => '3', 'fee_terms_note' => '1.5% for launch year'])
            ->assertHasNoTableActionErrors();

        $gg = $this->gg->fresh();
        $this->assertSame('active', $gg->status);
        $this->assertSame('1.50', (string) $gg->commission_percent);
        $this->assertSame('3.00', (string) $gg->per_booking_fee);
        $this->assertSame('1.5% for launch year', $gg->fee_terms_note);
        $this->assertNotNull($gg->fee_terms_agreed_at);
        $this->assertStringContainsString('negotiated', Company::feeRatesLabel($gg));
    }
}

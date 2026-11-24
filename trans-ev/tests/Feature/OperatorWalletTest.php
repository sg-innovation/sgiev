<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\WalletPage;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\PlatformFeeService;
use App\Services\WalletService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Prepaid operator wallets: bookings only when the wallet covers the platform fee; fee taken on payment. */
class OperatorWalletTest extends TestCase
{
    use RefreshDatabase;

    private Company $gg;

    private Station $station;

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->gg = Company::where('slug', 'greengrid-demo')->first();
        $this->gg->update(['billing_mode' => 'wallet', 'commission_percent' => 5, 'per_booking_fee' => 0]);
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->owner = User::where('email', 'owner@greengrid.test')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(string $at = '2026-11-25 12:00', array $extra = [])
    {
        $charger = $this->station->chargers()->first();

        return $this->actingAs($this->customer)->post("/book/{$this->station->slug}", array_merge([
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '27', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ], $extra));
    }

    private function pay(Booking $b): void
    {
        $this->actingAs($this->customer)->post("/account/bookings/{$b->code}/pay");
        $payment = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($q));
    }

    private function fund(string $amount): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        app(WalletService::class)->adjust($this->gg, $amount, 'Test funds', 'UTR1', $admin);
    }

    public function test_empty_wallet_blocks_bookings_and_alerts_the_operator(): void
    {
        $this->book()->assertSessionHasErrors('start');
        $this->assertSame(0, Booking::count());
        Notification::assertSentTo($this->owner, \App\Notifications\PartnerUpdate::class, fn ($n) => $n->template === 'wallet.low_balance');
    }

    public function test_fee_is_reserved_on_hold_and_taken_on_payment(): void
    {
        $this->fund('1000.00');
        $this->book()->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $expected = app(WalletService::class)->feeFor($this->gg, (string) $b->taxable_amount)['total'];
        $this->assertSame($expected, (string) $b->platform_fee);

        $svc = app(WalletService::class);
        $this->assertSame(bcsub('1000.00', $expected, 2), $svc->available($this->gg->fresh()), 'held booking reserves its fee');
        $this->assertSame('1000.00', (string) $svc->walletFor($this->gg)->balance);

        $this->pay($b);
        $this->assertSame('confirmed', $b->fresh()->status->value);
        $this->assertSame(bcsub('1000.00', $expected, 2), (string) $svc->walletFor($this->gg)->fresh()->balance);
        $this->assertSame(1, WalletTransaction::where('booking_id', $b->id)->where('type', 'platform_fee')->count());
    }

    public function test_bookings_stop_when_the_balance_runs_out(): void
    {
        $this->fund('1000.00');
        $this->book()->assertSessionHasNoErrors();
        $fee = (string) Booking::latest('id')->first()->platform_fee;
        // Leave exactly less than one more fee available.
        app(WalletService::class)->post($this->gg, 'manual_debit', bcmul(bcsub(bcsub('1000.00', $fee, 2), '1.00', 2), '-1', 2), 'test');
        $this->book('2026-11-25 14:00')->assertSessionHasErrors('start');
    }

    public function test_refund_returns_the_fee_pro_rata(): void
    {
        $this->fund('1000.00');
        $this->book('2026-11-24 16:00')->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->pay($b);
        $before = (string) app(WalletService::class)->walletFor($this->gg)->fresh()->balance;

        // Cancel 6 hours ahead: full refund under the default policy → full fee back.
        $this->actingAs($this->customer)->post("/account/bookings/{$b->code}/cancel")->assertSessionHas('success');
        $after = (string) app(WalletService::class)->walletFor($this->gg)->fresh()->balance;
        $this->assertSame(bcadd($before, (string) $b->platform_fee, 2), $after);
    }

    public function test_trans_ev_coupons_are_credited_to_the_wallet(): void
    {
        $this->fund('1000.00');
        Coupon::create(['code' => 'ONUS', 'name' => 'x', 'type' => 'flat', 'value' => 40, 'is_active' => true, 'per_user_limit' => null]);
        $this->book('2026-11-25 12:00', ['coupon_code' => 'ONUS'])->assertSessionHasNoErrors();
        $this->pay(Booking::latest('id')->first());
        $this->assertSame('40.00', (string) WalletTransaction::where('type', 'coupon_credit')->value('amount'));
    }

    public function test_online_recharge_credits_the_wallet(): void
    {
        $url = app(PaymentService::class)->startRecharge($this->gg, '2500', $this->owner);
        $this->assertStringContainsString('/dev/sabpaisa/checkout/', $url);
        $payment = Payment::where('purpose', 'wallet')->first();
        $this->assertNull($payment->booking_id);
        $this->assertSame(Company::where('is_platform', true)->value('id'), $payment->company_id, 'paid to Trans EV');

        [, $q] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->actingAs($this->owner)->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($q))->assertRedirect(WalletPage::getUrl(panel: 'admin'));
        $this->assertSame('2500.00', (string) app(WalletService::class)->walletFor($this->gg)->fresh()->balance);

        // Refreshing the return page does not credit twice.
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($q));
        $this->assertSame(1, WalletTransaction::where('type', 'recharge')->count());
    }

    public function test_minimum_recharge(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PaymentService::class)->startRecharge($this->gg, '10', $this->owner);
    }

    public function test_invoice_mode_operators_are_not_blocked(): void
    {
        $this->gg->update(['billing_mode' => 'invoice']);
        $this->book()->assertSessionHasNoErrors();
        $this->assertNull(Booking::latest('id')->first()->platform_fee);
    }

    public function test_monthly_statement_is_settled_from_the_wallet(): void
    {
        $this->fund('5000.00');
        $this->gg->plan->update(['price_monthly' => 100]);
        $this->book('2026-11-24 16:00')->assertSessionHasNoErrors();
        $this->pay(Booking::latest('id')->first());
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);

        $svc = app(PlatformFeeService::class);
        $s = $svc->issue($svc->generate($this->gg->fresh(), Carbon::parse('2026-11-01')), $admin);
        $this->assertSame('paid', $s->status);
        $this->assertSame('Prepaid wallet', $s->payment_reference);
        $this->assertSame('-118.00', (string) WalletTransaction::where('type', 'subscription')->value('amount'));
    }

    public function test_ledger_is_append_only_and_wallet_page_is_scoped(): void
    {
        $this->fund('100.00');
        $id = WalletTransaction::value('id');
        try {
            DB::table('wallet_transactions')->where('id', $id)->update(['amount' => 999]);
            $this->fail('should be blocked');
        } catch (QueryException) {
        }

        $this->actingAs($this->owner)->get(WalletPage::getUrl())->assertOk()->assertSee('100.00');
        // Asking for another company's wallet still shows only the operator's own.
        $this->get(WalletPage::getUrl(['company' => Company::where('is_platform', true)->value('id')]))->assertOk()->assertSee('100.00');
    }
}

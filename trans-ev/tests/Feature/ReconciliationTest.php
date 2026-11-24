<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Reconciliation\Pages\ListReconciliationExceptions;
use App\Filament\Resources\Reconciliation\ReconciliationResource;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReconciliationException;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\PartnerUpdate;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\ReconciliationService;
use App\Services\WalletService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** B8: daily reconciliation fills a deduplicated exceptions queue; fixes, auto-clear, reopen and access rules. */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->admin = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $this->admin->assignRole(Role::SuperAdmin->value);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->app['session']->flush();

        return $this->actingAs($user);
    }

    private function book(string $at = '2026-11-25 12:00'): Booking
    {
        $charger = $this->station->chargers()->first();
        $this->as($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->station->state_code, 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();

        return Booking::latest('id')->first();
    }

    private function pay(Booking $b, string $status = 'SUCCESS'): Payment
    {
        $this->as($this->customer)->post("/account/bookings/{$b->code}/pay");
        $payment = $b->payments()->latest('id')->first();
        [, $q] = SimulatedSabPaisaGateway::complete($payment, $status);
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($q));

        return $payment->fresh();
    }

    private function reconcile(bool $enquire = true)
    {
        return app(ReconciliationService::class)->run(null, $enquire);
    }

    private function open(?string $type = null)
    {
        return ReconciliationException::where('status', 'open')->when($type, fn ($q) => $q->where('type', $type))->get();
    }

    public function test_clean_books_produce_no_exceptions(): void
    {
        $this->pay($this->book());
        $this->travel(3)->hours();
        $run = $this->reconcile();
        $this->assertNull($run->error);
        $this->assertSame(1, $run->stats['enquiries']);
        $this->assertCount(0, $this->open(), $this->open()->pluck('summary')->implode('; '));
    }

    public function test_gateway_says_paid_but_we_recorded_failed(): void
    {
        $p = $this->pay($this->book(), 'FAILED');
        $this->assertSame(Payment::FAILED, $p->status);
        SimulatedSabPaisaGateway::complete($p, 'SUCCESS'); // the bank later settles it

        $this->reconcile();
        $ex = $this->open('gateway_status')->first();
        $this->assertNotNull($ex);
        $this->assertSame('critical', $ex->severity);
        $this->assertStringContainsString('may have been charged', $ex->summary);
        Notification::assertSentTo($this->admin, PartnerUpdate::class, fn ($n) => $n->template === 'reconciliation.alert');
    }

    public function test_findings_are_deduplicated_and_cleared_when_fixed(): void
    {
        $b = $this->book();
        $this->pay($b);
        DB::table('bookings')->where('id', $b->id)->update(['status' => 'expired']); // paid, no confirmed booking, no refund
        $this->travel(2)->hours();

        $this->reconcile(false);
        $this->reconcile(false);
        $ex = $this->open('paid_not_booked')->sole();
        $this->assertSame(2, $ex->occurrences);

        DB::table('bookings')->where('id', $b->id)->update(['status' => 'confirmed']);
        $run = $this->reconcile(false);
        $this->assertSame(1, $run->cleared_exceptions);
        $this->assertSame('resolved', $ex->fresh()->status);
    }

    public function test_resolved_by_hand_but_still_wrong_reopens_while_ignored_stays_closed(): void
    {
        $b = $this->book();
        DB::table('bookings')->where('id', $b->id)->update(['status' => 'confirmed']); // confirmed with no payment
        $this->reconcile(false);
        $ex = $this->open('booked_unpaid')->sole();

        $this->actingAs($this->admin);
        ReconciliationResource::close($ex, 'resolved', 'Checked');
        $this->reconcile(false);
        $this->assertSame('open', $ex->fresh()->status);
        $this->assertStringContainsString('Reopened', $ex->fresh()->resolution_note);

        ReconciliationResource::close($ex->fresh(), 'ignored', 'Free test booking');
        $this->reconcile(false);
        $this->assertSame('ignored', $ex->fresh()->status);
    }

    public function test_missing_invoice_is_fixed_in_one_click(): void
    {
        // Invoice issue fails at payment time (the booking stays confirmed).
        $this->instance(\App\Services\InvoiceService::class, \Mockery::mock(\App\Services\InvoiceService::class, fn ($m) => $m->shouldReceive('issueForBooking')->andThrow(new \RuntimeException('PDF down'))));
        $b = $this->book();
        $this->pay($b);
        $this->app->forgetInstance(\App\Services\InvoiceService::class);
        $this->assertSame(0, Invoice::where('booking_id', $b->id)->count());

        $this->travel(3)->hours();
        $this->reconcile(false);
        $ex = $this->open('invoice_missing')->sole();
        $this->assertSame('medium', $ex->severity);

        $this->actingAs($this->admin);
        $this->assertTrue(app(ReconciliationService::class)->fix($ex));
        $this->assertSame(1, Invoice::where('booking_id', $b->id)->count());
        $this->assertSame('resolved', $ex->fresh()->status);
    }

    public function test_missing_wallet_fee_is_fixed_in_one_click(): void
    {
        $gg = $this->station->company;
        $b = $this->book();
        $this->pay($b); // invoice billing: no wallet fee taken
        // Operator moved to wallet billing with this booking's fee still owed.
        $gg->update(['billing_mode' => 'wallet']);
        app(WalletService::class)->adjust($gg, '1000.00', 'Funds', 'UTR', $this->admin);
        DB::table('bookings')->where('id', $b->id)->update(['platform_fee' => '29.50']);

        $this->reconcile(false);
        $ex = $this->open('wallet_fee_missing')->sole();
        $this->assertTrue(ReconciliationService::canFix($ex));
        $this->actingAs($this->admin);
        $this->assertTrue(app(ReconciliationService::class)->fix($ex));
        $this->assertSame('-29.50', (string) WalletTransaction::where('booking_id', $b->id)->where('type', 'platform_fee')->value('amount'));
        $this->assertSame('resolved', $ex->fresh()->status);
    }

    public function test_stuck_pending_payment_is_flagged(): void
    {
        $b = $this->book();
        $this->as($this->customer)->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->first();
        $p->forceFill(['status' => Payment::PENDING, 'expires_at' => now()->addDays(5)])->save();
        $this->travel(2)->hours();
        $this->reconcile();
        $this->assertCount(1, $this->open('payment_pending'));
    }

    public function test_queue_access_follows_permissions_and_company(): void
    {
        $b = $this->book();
        DB::table('bookings')->where('id', $b->id)->update(['status' => 'confirmed']);
        $this->reconcile(false);
        $other = ReconciliationException::create([
            'fingerprint' => 'x', 'type' => 'booked_unpaid', 'severity' => 'high', 'company_id' => Company::where('is_platform', true)->value('id'),
            'summary' => 'Other operator problem', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $mine = $this->open('booked_unpaid')->firstWhere('company_id', $this->station->company_id);

        $owner = User::where('email', 'owner@greengrid.test')->first();
        $this->as($owner)->get(ReconciliationResource::getUrl())->assertOk();
        Livewire::test(ListReconciliationExceptions::class)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden('run')->assertTableActionHidden('resolve', $mine);

        $this->as($this->admin)->get(ReconciliationResource::getUrl())->assertOk();
        Livewire::test(ListReconciliationExceptions::class)
            ->assertCanSeeTableRecords([$mine, $other])->assertActionVisible('run')
            ->callTableAction('resolve', $mine, ['note' => 'Collected cash at site']);
        $this->assertSame('resolved', $mine->fresh()->status);
        $this->assertSame($this->admin->id, $mine->fresh()->resolved_by);

        $this->as($this->customer)->get(ReconciliationResource::getUrl())->assertForbidden();
    }
}

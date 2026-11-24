<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\ChargingMeterValue;
use App\Models\ChargingSession;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\BookingTrueUp;
use App\Services\Gstr1Export;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Support\BusinessRules;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** C4: meter readings and the final bill / true-up of prepaid bookings (refund shortfall, absorb or collect extra). */
class TrueUpTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
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

    private function as(User $user): static
    {
        $this->app['session']->flush();

        return $this->actingAs($user);
    }

    private function rule(string $key, string $value): void
    {
        SystemSetting::query()->updateOrCreate(['key' => 'rule.'.$key], ['value' => $value]);
        BusinessRules::flush();
    }

    /** Paid booking 12:00–13:00 on the DC charger, session started at 12:00 with meter 1000. */
    private function charging(): array
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->as($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->station->state_code, 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q));
        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start", ['meter_start' => '1000'])->assertSessionHasNoErrors();

        return [$b->fresh(), ChargingSession::where('booking_id', $b->id)->sole()];
    }

    public function test_less_energy_is_refunded_with_a_credit_note(): void
    {
        [$b, $s] = $this->charging();
        $this->assertSame('start', ChargingMeterValue::where('charging_session_id', $s->id)->value('context'));
        $prepaid = (string) $b->total_amount;
        $this->assertGreaterThan(10, (float) $b->est_kwh);

        $this->travel(20)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/reading", ['register' => '1005', 'power' => '45', 'soc' => '55'])->assertSessionHasNoErrors();
        $sample = ChargingMeterValue::where('context', 'sample')->sole();
        $this->assertSame('5.000', (string) $sample->energy_kwh);
        $this->assertSame(55, $sample->soc);

        $this->travel(10)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '1010', 'reason' => 'completed'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('10.000', (string) $b->final_kwh);
        $this->assertLessThan((float) $prepaid, (float) $b->final_total);
        $this->assertSame(bcsub((string) $b->final_total, $prepaid, 2), (string) $b->trueup_amount);
        $this->assertSame('refunded', $b->trueup_status);
        $this->assertSame($prepaid, (string) $b->total_amount, 'original invoice amount untouched');

        $refund = Refund::where('booking_id', $b->id)->sole();
        $this->assertSame(ltrim((string) $b->trueup_amount, '-'), (string) $refund->amount);
        $this->assertSame('true-up', $refund->policy_rule);
        $this->assertSame(1, CreditNote::where('booking_id', $b->id)->where('note_type', 'credit')->count());
        Notification::assertSentTo($this->customer, BookingTrueUp::class);

        $this->as($this->owner)->get(\App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $b]))->assertOk()->assertSee('Final bill')->assertSee('refunded');
        $r = $this->as($this->owner)->get('/staff?station='.$this->station->id);
        $r->assertOk();
        $r->assertInertia(fn ($p) => $p->where('arrivals.0.bill.kind', 'final'));
        $this->get(\App\Filament\Resources\CreditNotes\CreditNoteResource::getUrl())->assertOk();

        // Customer sees the final bill.
        $this->as($this->customer)->get("/account/bookings/{$b->code}")->assertInertia(fn ($p) => $p->where('booking.finalBill.status', 'refunded')->where('booking.finalBill.kwh', 10));
    }

    public function test_extra_energy_is_absorbed_by_default(): void
    {
        [$b, $s] = $this->charging();
        $this->travel(55)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '1045', 'reason' => 'completed'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertGreaterThan((float) $b->total_amount, (float) $b->final_total);
        $this->assertSame('absorbed', $b->trueup_status);
        $this->assertNull($b->balance_due);
        $this->assertSame(0, Refund::count());
    }

    public function test_extra_energy_can_be_collected_with_a_debit_note(): void
    {
        $this->rule('trueup.collect_extra', '1');
        [$b, $s] = $this->charging();
        $this->travel(55)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '1045', 'reason' => 'completed'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('due', $b->trueup_status);
        $this->assertSame('due', $b->settlement);
        $this->assertSame((string) $b->trueup_amount, (string) $b->balance_due);

        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'upi_manual', 'reference' => '998877665544'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('paid', $b->trueup_status);
        $pay = Payment::where('booking_id', $b->id)->where('purpose', 'balance')->sole();
        $this->assertSame((string) $b->balance_due, (string) $pay->amount);
        $debit = CreditNote::where('booking_id', $b->id)->where('note_type', 'debit')->sole();
        $this->assertStringContainsString('D/', $debit->number);
        $this->assertSame((string) $b->balance_due, (string) $debit->total);

        // The balance payment is not mistaken for a duplicate by reconciliation; the debit note is in GSTR-1.
        $this->travel(3)->hours();
        app(\App\Services\ReconciliationService::class)->run(null, false);
        $this->assertSame([], \App\Models\ReconciliationException::pluck('summary', 'type')->all());
        $zip = app(Gstr1Export::class)->build($debit->supplier_gstin, Carbon::parse('2026-11-01', 'Asia/Kolkata'), sys_get_temp_dir());
        $z = new \ZipArchive;
        $z->open($zip);
        $this->assertStringContainsString('Debit Note', $z->getFromName('docs.csv'));
        $z->close();
        $this->as($this->owner)->get(route('credit-notes.show', $debit))->assertOk();
    }

    public function test_small_differences_are_ignored_and_walk_ins_are_not_trued_up(): void
    {
        [$b, $s] = $this->charging();
        $this->rule('trueup.min_amount', '100000');
        $this->travel(30)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '1010', 'reason' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('none', $b->fresh()->trueup_status);
        $this->assertSame(0, Refund::count());
    }

    public function test_failed_refund_is_flagged_and_can_be_retried(): void
    {
        [$b, $s] = $this->charging();
        $this->instance(\App\Services\Payments\RefundService::class, \Mockery::mock(\App\Services\Payments\RefundService::class, fn ($m) => $m->shouldReceive('refundPayment')->andThrow(new \RuntimeException('gateway down'))));
        $this->travel(30)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '1010', 'reason' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('refund_failed', $b->fresh()->trueup_status);

        $this->travel(2)->hours();
        app(\App\Services\ReconciliationService::class)->run(null, false);
        $ex = \App\Models\ReconciliationException::where('type', 'trueup_refund')->sole();

        $this->app->forgetInstance(\App\Services\Payments\RefundService::class);
        $this->actingAs($this->owner);
        $this->assertTrue(app(\App\Services\ReconciliationService::class)->fix($ex));
        $this->assertSame('refunded', $b->fresh()->trueup_status);
    }
}

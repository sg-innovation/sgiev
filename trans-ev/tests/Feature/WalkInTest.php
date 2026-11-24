<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Company;
use App\Models\Connector;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\PlatformFeeService;
use App\Services\ReconciliationService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** C3: walk-in charging billed on actual energy, paid by cash, UPI or online link; blocks online slots while in use. */
class WalkInTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private User $owner;

    private Connector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
        $this->owner = User::where('email', 'owner@greengrid.test')->first();
        $this->connector = $this->station->chargers()->where('type', 'DC')->first()->connectorPoints()->first();
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

    private function startWalkIn(array $extra = []): Booking
    {
        $this->as($this->owner)->post('/staff/walk-ins', array_merge([
            'connector_id' => $this->connector->id, 'registration_no' => 'mh 12 xy 9876', 'make' => 'MG', 'model' => 'ZS EV',
            'name' => 'Walk In Driver', 'phone' => '9876501234', 'email' => 'walkin@example.com', 'minutes' => 60, 'meter_start' => '500', 'informed' => '1',
        ], $extra))->assertSessionHasNoErrors();

        return Booking::where('source', 'walk_in')->latest('id')->first();
    }

    private function stop(Booking $b, string $meter = '520'): void
    {
        $this->travel(30)->minutes();
        $s = ChargingSession::where('booking_id', $b->id)->sole();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => $meter, 'reason' => 'completed'])->assertSessionHasNoErrors();
    }

    public function test_walk_in_starts_charging_and_blocks_online_bookings(): void
    {
        $b = $this->startWalkIn();
        $this->assertNull($b->user_id);
        $this->assertSame('checked_in', $b->status->value);
        $this->assertSame('MH12XY9876', $b->vehicle_snapshot['registration_no']);
        $this->assertSame('walk_in', $b->checkIn->method);
        $this->assertSame('charging', $this->connector->fresh()->live_status->value);
        $this->assertSame('500.000', (string) ChargingSession::where('booking_id', $b->id)->value('meter_start_kwh'));

        // An online customer can't book this connector for the walk-in's time.
        $avail = app(\App\Services\AvailabilityService::class);
        $this->assertFalse($avail->isConnectorFree($this->connector, now()->addMinutes(15), now()->addMinutes(45)));
    }

    public function test_bill_uses_energy_delivered_and_cash_issues_the_invoice(): void
    {
        $b = $this->startWalkIn();
        $this->stop($b, '520.5');
        $b->refresh();
        $this->assertSame('completed', $b->status->value);
        $this->assertSame('due', $b->settlement);
        $this->assertSame('20.500', (string) $b->est_kwh);
        $energyLine = collect($b->price_lines)->firstWhere('type', 'energy');
        $this->assertSame('20.500', number_format((float) $energyLine['qty'], 3, '.', ''));
        $this->assertTrue($b->end_at->lte(now()), 'connector freed when charging stops');
        $this->assertTrue(app(\App\Services\AvailabilityService::class)->isConnectorFree($this->connector, now()->addMinutes(5), now()->addMinutes(30)));
        $this->assertSame('available', $this->connector->fresh()->live_status->value);

        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'cash'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('paid', $b->settlement);
        $p = Payment::where('booking_id', $b->id)->sole();
        $this->assertSame('cash', $p->gateway);
        $this->assertSame('offline', $p->environment);
        $this->assertSame($this->owner->id, $p->collected_by);
        $inv = Invoice::where('booking_id', $b->id)->sole();
        $this->assertSame((string) $b->total_amount, (string) $inv->total);

        $this->get(\App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $b]))->assertOk()->assertSee('MH12XY9876');
        $this->get(\App\Filament\Resources\Bookings\BookingResource::getUrl('index'))->assertOk();
        $this->get(route('bookings.invoice', $b))->assertOk();

        // Paying twice is refused; reconciliation sees nothing wrong and never asks the gateway about cash.
        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'cash'])->assertSessionHasErrors('method');
        $this->travel(3)->hours();
        $run = app(ReconciliationService::class)->run();
        $this->assertSame(0, $run->stats['enquiries']);
        $this->assertSame(0, $run->new_exceptions);
    }

    public function test_upi_needs_a_reference_and_cash_cannot_be_refunded_online(): void
    {
        $b = $this->startWalkIn();
        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'cash'])->assertSessionHasErrors('method'); // still charging
        $this->stop($b);
        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'upi_manual'])->assertSessionHasErrors('reference');
        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'upi_manual', 'reference' => '412345678901'])->assertSessionHasNoErrors();
        $p = Payment::where('booking_id', $b->id)->sole();
        $this->assertSame('412345678901', $p->bank_rrn);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Payments\RefundService::class)->refundPayment($p, '10.00', 'test', 'manual', $this->owner);
    }

    public function test_online_link_settles_the_walk_in(): void
    {
        $b = $this->startWalkIn();
        $this->stop($b);
        $json = $this->postJson("/staff/bookings/{$b->code}/payment-link")->assertOk()->json();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $json['qr']);
        $p = Payment::where('booking_id', $b->id)->sole();
        $this->assertNull($p->user_id);

        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->app['session']->flush();
        auth()->logout();
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q))->assertRedirect(route('walk-in.paid', $p->uuid));
        $this->get(route('walk-in.paid', $p->uuid))->assertOk()->assertSee('Payment received');
        $this->assertSame('paid', $b->fresh()->settlement);
        $this->assertNotNull($b->fresh()->invoice);
        $this->get(URL::temporarySignedRoute('walk-in.invoice', now()->addDay(), ['payment' => $p->uuid]))->assertOk();
        $this->get(route('walk-in.invoice', ['payment' => $p->uuid]))->assertForbidden(); // unsigned
    }

    public function test_walk_in_must_fit_before_the_next_online_booking(): void
    {
        // An online booking on this connector at 11:00.
        $customer = User::factory()->create(['phone' => '9876543210']);
        $customer->assignRole(Role::Customer->value);
        $this->as($customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $this->connector->charger_id, 'start' => Carbon::parse('2026-11-24 11:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $this->connector->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Online', 'email' => 'o@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '27', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();

        $this->as($this->owner)->post('/staff/walk-ins', [
            'connector_id' => $this->connector->id, 'registration_no' => 'MH12XY9876', 'name' => 'Walk In', 'phone' => '9876501234', 'minutes' => 120, 'informed' => '1',
        ])->assertSessionHasErrors('minutes');
        $this->assertSame(0, Booking::where('source', 'walk_in')->count());
    }

    public function test_permissions_and_platform_fee_rule(): void
    {
        $manager = User::factory()->create(['company_id' => $this->station->company_id]);
        $manager->assignRole(Role::Auditor->value);
        $this->as($manager)->post('/staff/walk-ins', [
            'connector_id' => $this->connector->id, 'registration_no' => 'MH12XY9876', 'name' => 'X', 'phone' => '9876501234', 'minutes' => 30, 'informed' => '1',
        ])->assertSessionHasErrors('connector_id');

        $b = $this->startWalkIn();
        $this->stop($b);
        $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'cash']);
        // Walk-ins are left out of platform-fee sales unless Business rules say otherwise.
        $sales = app(PlatformFeeService::class)->sales(Company::find($this->station->company_id), now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(0, $sales['count'] ?? $sales[0] ?? 0);
    }
}

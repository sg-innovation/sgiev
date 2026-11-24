<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Company;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** C2: Staff Console — arrivals, check-in, manual start/stop with meter readings, no-show, connector status, scoping. */
class StaffConsoleTest extends TestCase
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

    private function paid(string $at = '2026-11-24 12:00'): Booking
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first() ?? $this->station->chargers()->first();
        $this->as($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->station->state_code, 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q));

        return $b->fresh();
    }

    public function test_console_lists_todays_arrivals_and_chargers(): void
    {
        $b = $this->paid();
        $this->as($this->owner)->get('/staff?station='.$this->station->id)->assertOk()->assertInertia(fn ($p) => $p->component('staff/console')
            ->where('station.id', $this->station->id)
            ->where('arrivals.0.code', $b->code)->where('arrivals.0.registration', 'MH12AB1234')
            ->where('can.sessions', true)->where('can.checkIn', true)->has('chargers'));

        $this->as($this->customer)->get('/staff')->assertForbidden();
    }

    public function test_full_session_with_meter_readings(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 11:55', 'Asia/Kolkata'));

        // Start charging checks the customer in first.
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start", ['meter_start' => '1000.000'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('checked_in', $b->status->value);
        $s = ChargingSession::where('booking_id', $b->id)->sole();
        $this->assertSame('active', $s->status);
        $this->assertSame('charging', $b->connectorPoint->fresh()->live_status->value);

        // Double tap is harmless; the connector can't be set available while charging.
        $this->post("/staff/bookings/{$b->code}/start", ['meter_start' => '1000'])->assertSessionHasNoErrors();
        $this->assertSame(1, ChargingSession::count());
        $this->post("/staff/connectors/{$b->connector_id}/status", ['status' => 'available'])->assertSessionHasErrors('status');

        $this->travel(40)->minutes();
        // Lower than start, and more than the charger could deliver, are refused.
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '999', 'reason' => 'completed'])->assertSessionHasErrors('meter_stop');
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '5000', 'reason' => 'completed'])->assertSessionHasErrors('meter_stop');

        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '1018.25', 'reason' => 'completed'])->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame('completed', $s->status);
        $this->assertSame('18.250', (string) $s->energy_kwh);
        $this->assertSame('meter', $s->energy_source);
        $this->assertSame($this->owner->id, $s->stopped_by);
        $this->assertSame('completed', $b->fresh()->status->value);
        $this->assertSame('available', $b->connectorPoint->fresh()->live_status->value);
    }

    public function test_stop_without_meter_uses_energy_from_the_charger(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start")->assertSessionHasNoErrors();
        $s = ChargingSession::sole();
        $this->travel(30)->minutes();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['reason' => 'customer'])->assertSessionHasErrors('energy');
        $this->post("/staff/sessions/{$s->uuid}/stop", ['energy' => '6.5', 'reason' => 'fault', 'note' => 'Gun overheated'])->assertSessionHasNoErrors();
        $this->assertSame('entered', $s->fresh()->energy_source);
        $this->assertSame('faulted', $b->connectorPoint->fresh()->live_status->value, 'a fault stop marks the connector faulted');
    }

    public function test_no_show_and_connector_status(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 12:20', 'Asia/Kolkata'));
        $this->as($this->owner)->get('/staff?station='.$this->station->id)->assertInertia(fn ($p) => $p->where('arrivals.0.canNoShow', true));
        $this->post("/staff/bookings/{$b->code}/no-show")->assertSessionHasNoErrors();
        $this->assertSame('no_show', $b->fresh()->status->value);

        $this->post("/staff/connectors/{$b->connector_id}/status", ['status' => 'faulted', 'note' => 'Cable damaged'])->assertSessionHasNoErrors();
        $c = $b->connectorPoint->fresh();
        $this->assertSame('faulted', $c->live_status->value);
        $this->assertSame('Cable damaged', $c->live_note);
        $this->post("/staff/connectors/{$b->connector_id}/status", ['status' => 'charging'])->assertSessionHasErrors('status');
    }

    public function test_staff_of_other_companies_and_unassigned_managers_are_refused(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));

        $manager = User::factory()->create(['company_id' => $this->station->company_id]);
        $manager->assignRole(Role::StationManager->value); // not assigned to this station
        $this->as($manager)->get('/staff')->assertOk()->assertInertia(fn ($p) => $p->where('station', null));
        $this->post("/staff/bookings/{$b->code}/start")->assertSessionHasErrors('booking');
        $this->post("/staff/connectors/{$b->connector_id}/status", ['status' => 'faulted'])->assertForbidden();

        $manager->stations()->attach($this->station->id);
        $this->as($manager)->post("/staff/bookings/{$b->code}/start")->assertSessionHasNoErrors();
        $this->assertSame(1, ChargingSession::count());

        $other = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $other->assignRole(Role::OperationsAdmin->value);
        $this->as($other)->post('/staff/sessions/'.ChargingSession::value('uuid').'/stop', ['energy' => '1', 'reason' => 'completed'])->assertSessionHasErrors('session');
    }

    public function test_scan_box_checks_in_by_code(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 11:50', 'Asia/Kolkata'));
        $this->as($this->owner)->post('/staff/scan', ['input' => $b->code])->assertSessionHas('success');
        $this->assertSame('checked_in', $b->fresh()->status->value);
    }
}

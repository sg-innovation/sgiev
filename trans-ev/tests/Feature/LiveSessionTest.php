<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\TrueUpService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** C9: customer live session tracking — energy, cost so far, estimated finish, stop request to staff. */
class LiveSessionTest extends TestCase
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

    private function paid(): Booking
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->as($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '27', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q));

        return $b->fresh();
    }

    public function test_live_payload_through_the_session(): void
    {
        $b = $this->paid();
        $this->as($this->customer)->getJson("/account/bookings/{$b->code}/live")->assertOk()->assertJsonPath('state', 'not_started');

        $this->travelTo(Carbon::parse('2026-11-24 11:55', 'Asia/Kolkata'));
        $this->as($this->owner)->post('/staff/scan', ['input' => $b->code])->assertSessionHas('success');
        $this->as($this->customer)->getJson("/account/bookings/{$b->code}/live")->assertJsonPath('state', 'waiting_start');

        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start", ['meter_start' => '2000'])->assertSessionHasNoErrors();
        $this->travel(15)->minutes();
        $s = ChargingSession::sole();
        $this->post("/staff/sessions/{$s->uuid}/reading", ['register' => '2012.5', 'power' => '48', 'soc' => '45'])->assertSessionHasNoErrors();

        $live = $this->as($this->customer)->getJson("/account/bookings/{$b->code}/live")->assertOk()->json();
        $this->assertSame('charging', $live['state']);
        $this->assertSame(12.5, $live['session']['energy']);
        $this->assertTrue($live['session']['measured']);
        $this->assertSame(48, (int) $live['session']['power']);
        $this->assertSame(45, $live['session']['soc']);
        $this->assertSame(15, $live['session']['minutes']);
        // Cost so far is exactly the final bill at 12.5 kWh.
        $this->assertSame((float) app(TrueUpService::class)->finalBill($b->fresh(), '12.500')['total_amount'], $live['session']['cost']);
        $this->assertSame((float) $b->total_amount, $live['session']['prepaid']);
        $this->assertNotNull($live['session']['finishAt']);
        $this->assertTrue($live['canRequestStop']);

        // Customer asks to stop: staff see it; asking again is harmless.
        $this->post("/account/bookings/{$b->code}/stop-request")->assertSessionHas('success');
        $this->post("/account/bookings/{$b->code}/stop-request")->assertSessionHas('success');
        $this->assertNotNull($b->fresh()->stop_requested_at);
        $this->as($this->owner)->get('/staff?station='.$this->station->id)->assertInertia(fn ($p) => $p->where('arrivals.0.stopRequestedAt', fn ($v) => $v !== null));

        // Staff stop → finished.
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => '2014', 'reason' => 'customer'])->assertSessionHasNoErrors();
        $this->as($this->customer)->getJson("/account/bookings/{$b->code}/live")->assertJsonPath('state', 'finished')->assertJsonPath('session.energy', 14);
    }

    public function test_access_and_stop_rules(): void
    {
        $b = $this->paid();
        $this->as(User::factory()->create())->getJson("/account/bookings/{$b->code}/live")->assertForbidden();
        // No session running yet.
        $this->as($this->customer)->post("/account/bookings/{$b->code}/stop-request")->assertSessionHasErrors('stop');
        $this->as(User::factory()->create())->post("/account/bookings/{$b->code}/stop-request")->assertSessionHasErrors('stop');
    }

    public function test_energy_is_estimated_without_readings(): void
    {
        $b = $this->paid();
        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start")->assertSessionHasNoErrors();
        $this->travel(30)->minutes();
        $live = $this->as($this->customer)->getJson("/account/bookings/{$b->code}/live")->json();
        $this->assertFalse($live['session']['measured']);
        $this->assertFalse($live['session']['powerMeasured']);
        $this->assertGreaterThan(0, $live['session']['energy']);
        $this->as($this->customer)->get("/account/bookings/{$b->code}")->assertOk();
    }
}

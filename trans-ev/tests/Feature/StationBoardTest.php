<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\StationBoard;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\StationBoardService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** C8: live station board — per-connector state, session progress, estimated finish and next free, scoping. */
class StationBoardTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
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

    private function paid(string $at): Booking
    {
        $u = User::factory()->create(['phone' => '9876543210']);
        $u->assignRole(Role::Customer->value);
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->as($u)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Asha Rao', 'email' => 'a@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '27', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q));

        return $b->fresh();
    }

    private function connector(array $board, int $id): array
    {
        return collect($board['chargers'])->flatMap(fn ($c) => $c['connectors'])->firstWhere('id', $id);
    }

    public function test_board_shows_charging_progress_and_estimates(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $next = $this->paid('2026-11-24 15:00');
        $svc = app(StationBoardService::class);

        $k = $this->connector($svc->build($this->station), $b->connector_id);
        $this->assertSame('available', $k['state']);
        $this->assertSame('now', $k['freeAt']);
        $this->assertSame($b->code, $k['next']['code']);

        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start", ['meter_start' => '1000'])->assertSessionHasNoErrors();
        $this->travel(20)->minutes();
        $s = ChargingSession::sole();
        $this->post("/staff/sessions/{$s->uuid}/reading", ['register' => '1010', 'power' => '40', 'soc' => '50'])->assertSessionHasNoErrors();

        $board = $svc->build($this->station->fresh());
        $k = $this->connector($board, $b->connector_id);
        $this->assertSame('charging', $k['state']);
        $this->assertSame('MH12AB1234', $k['booking']['registration']);
        $this->assertSame('Asha Rao', $k['booking']['customer']);
        $this->assertSame(10.0, $k['session']['energy']);
        $this->assertTrue($k['session']['measured']);
        $this->assertSame(40.0, $k['session']['power']);
        $this->assertSame(50, $k['session']['soc']);
        $this->assertSame(20, $k['session']['minutes']);
        $this->assertGreaterThan(0, $k['session']['progress']);
        // Remaining (target − 10 kWh) at 40 kW, capped at the booked end (1:00 PM).
        $remainingMin = (int) floor(max(0, (float) $b->est_kwh - 10) / 40 * 60);
        $expected = Carbon::parse('2026-11-24 12:20', 'Asia/Kolkata')->addMinutes($remainingMin);
        $expected = $expected->gt(Carbon::parse('2026-11-24 13:00', 'Asia/Kolkata')) ? Carbon::parse('2026-11-24 13:00', 'Asia/Kolkata') : $expected;
        $this->assertSame($expected->format('g:i A'), $k['session']['finishAt']);
        $this->assertNotSame('now', $k['freeAt']);
        $this->assertSame($next->code, $k['next']['code']);
        $this->assertSame(1, $board['counts']['charging']);
        $this->assertSame(1, $board['today']['sessions']);

        // Page renders for the operator.
        $this->get(StationBoard::getUrl(['station' => $this->station->id]))->assertOk()->assertSee('MH12AB1234')->assertSee('Estimated finish');
    }

    public function test_no_readings_means_estimated_energy_and_access_is_scoped(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata'));
        $this->as($this->owner)->post("/staff/bookings/{$b->code}/start")->assertSessionHasNoErrors();
        $this->travel(30)->minutes();
        $k = $this->connector(app(StationBoardService::class)->build($this->station), $b->connector_id);
        $this->assertFalse($k['session']['measured']);
        $this->assertGreaterThan(0, $k['session']['energy']);

        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $this->as($customer)->get(StationBoard::getUrl())->assertForbidden();

        // A station manager only sees assigned stations.
        $manager = User::factory()->create(['company_id' => $this->station->company_id]);
        $manager->assignRole(Role::StationManager->value);
        $this->as($manager)->get(StationBoard::getUrl(['station' => $this->station->id]))->assertOk()->assertSee('not assigned to any station');
        $manager->stations()->attach($this->station->id);
        $this->as($manager)->get(StationBoard::getUrl(['station' => $this->station->id]))->assertOk()->assertSee('GG-PUN-BNR-DC01');
    }
}

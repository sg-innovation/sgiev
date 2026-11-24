<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StationStatus;
use App\Models\Station;
use App\Models\StatusLog;
use App\Models\User;
use App\Services\StationStatusService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class StationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
    }

    public function test_public_pages_render(): void
    {
        $this->get('/')->assertOk();
        $this->get('/stations')->assertOk();
        $this->get('/stations/trans-ev-hub-connaught-place')->assertOk();
        $this->get('/pricing')->assertOk();
        $this->get('/stations/does-not-exist')->assertNotFound();
    }

    public function test_search_sorts_by_gps_distance(): void
    {
        // A point near India Gate, New Delhi.
        $res = $this->getJson('/stations/search?lat=28.6129&lng=77.2295')->assertOk();

        $data = $res->json('data');
        $this->assertSame('trans-ev-hub-connaught-place', $data[0]['slug']);
        $this->assertLessThan(5, $data[0]['distanceKm']);
        $distances = array_column($data, 'distanceKm');
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);
    }

    public function test_search_radius_and_filters(): void
    {
        $near = $this->getJson('/stations/search?lat=28.6129&lng=77.2295&radius=30')->json('data');
        $this->assertEqualsCanonicalizing(['DEL-CP', 'NOI-S18', 'GGN-CH'], array_column($near, 'code'));

        $ultra = $this->getJson('/stations/search?min_kw=150')->json('data');
        $this->assertSame(['PUN-EXP'], array_column($ultra, 'code'));

        $bookable = $this->getJson('/stations/search?available=1')->json('data');
        $this->assertNotContains('MUM-PWI', array_column($bookable, 'code')); // maintenance
        $this->assertNotContains('BLR-WTF', array_column($bookable, 'code')); // offline

        $this->getJson('/stations/search?lat=999&lng=1')->assertUnprocessable();
    }

    public function test_hidden_and_archived_stations_are_not_public(): void
    {
        $station = Station::where('code', 'DEL-CP')->first();
        $station->delete();

        $this->get('/stations/trans-ev-hub-connaught-place')->assertNotFound();
        $this->assertNotContains('DEL-CP', array_column($this->getJson('/stations/search')->json('data'), 'code'));
    }

    public function test_status_change_is_logged_and_reverts_automatically(): void
    {
        $station = Station::where('code', 'DEL-CP')->first();
        $service = app(StationStatusService::class);

        $service->change($station, StationStatus::Maintenance, 'Connector replacement', now()->addHour());
        $this->assertSame(StationStatus::Maintenance, $station->fresh()->status);
        $this->assertDatabaseHas('status_logs', ['loggable_id' => $station->id, 'to_status' => 'maintenance', 'reason' => 'Connector replacement']);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, $service->revertExpired());
        $this->assertSame(StationStatus::Online, $station->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_taking_a_station_offline_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(StationStatusService::class)->change(Station::first(), StationStatus::Offline);
    }

    public function test_status_logs_are_append_only(): void
    {
        $log = StatusLog::first();
        $this->expectException(LogicException::class);
        $log->update(['reason' => 'tampered']);
    }

    public function test_opening_hours(): void
    {
        $station = Station::where('code', 'AMD-SGH')->first(); // 06:00–22:00 IST
        $this->assertTrue($station->isOpenAt(Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata')));
        $this->assertFalse($station->isOpenAt(Carbon::parse('2026-11-24 23:30', 'Asia/Kolkata')));

        $station->holidayOverrides()->create(['date' => '2026-11-25', 'label' => 'Holiday', 'is_closed' => true]);
        $this->assertFalse($station->fresh()->isOpenAt(Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')));

        $this->assertTrue(Station::where('code', 'DEL-CP')->first()->isOpenAt(Carbon::parse('2026-11-24 03:00', 'Asia/Kolkata'))); // 24x7
        $this->assertTrue(Station::where('code', 'DEL-CP')->first()->isOpenAt(Carbon::parse('2026-11-24 23:59', 'Asia/Kolkata')));
    }

    public function test_current_tariff_and_gst_display(): void
    {
        $tariff = Station::where('code', 'PUN-EXP')->first()->currentTariff('DC');
        $this->assertSame('24.00', $tariff->rate_per_kwh);
        $this->assertSame('28.32', $tariff->ratePerKwhInclGst());
        $this->assertSame('998714', $tariff->sac_code);
    }

    public function test_admin_panel_access_is_role_based(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $this->actingAs($customer)->get('/admin')->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin)->get('/admin/stations')->assertOk();
        $this->actingAs($admin)->get('/admin/stations/create')->assertOk();
        $this->actingAs($admin)->get('/admin/stations/trans-ev-hub-connaught-place/edit')->assertOk();
        $this->actingAs($admin)->get('/admin/rule-sets')->assertOk();
        $this->actingAs($admin)->get('/admin/companies')->assertOk();
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_station_manager_only_sees_assigned_stations(): void
    {
        $assigned = Station::where('code', 'DEL-CP')->first();
        $manager = User::factory()->create(['company_id' => $assigned->company_id]);
        $manager->assignRole(Role::StationManager->value);
        $other = Station::where('code', 'GGN-CH')->first();
        $manager->stations()->attach($assigned);

        $this->assertTrue($manager->can('changeStatus', $assigned));
        $this->assertFalse($manager->can('changeStatus', $other));
        $this->assertFalse($manager->can('create', Station::class));
        $this->assertFalse($manager->can('forceDelete', $assigned));

        $this->actingAs($manager)->get("/admin/stations/{$other->slug}/edit")->assertNotFound();
    }
}

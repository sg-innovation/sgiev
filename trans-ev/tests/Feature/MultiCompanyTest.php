<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StationStatus;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Stations\StationResource;
use App\Models\Booking;
use App\Models\Company;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\BlogSeeder;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** SaaS marketplace: companies only ever see and change their own data. */
class MultiCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $platform;

    private Company $greengrid;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class, BlogSeeder::class]);
        $this->platform = Company::where('is_platform', true)->first();
        $this->greengrid = Company::where('slug', 'greengrid-demo')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(Company $company, Role $role): User
    {
        $u = User::factory()->create(['company_id' => $company->id]);
        $u->assignRole($role->value);

        return $u;
    }

    public function test_marketplace_lists_every_active_companys_stations_with_operator(): void
    {
        $codes = array_column($this->getJson('/stations/search')->json('data'), 'code');
        $this->assertContains('DEL-CP', $codes);
        $this->assertContains('GG-PUN-BNR', $codes);

        $gg = collect($this->getJson('/stations/search')->json('data'))->firstWhere('code', 'GG-PUN-BNR');
        $this->assertSame('GreenGrid', $gg['operator']['name']);
        $this->assertFalse($gg['operator']['isPlatform']);
    }

    public function test_suspended_company_disappears_and_cannot_log_in(): void
    {
        $owner = $this->staff($this->greengrid, Role::CompanyOwner);
        $this->greengrid->forceFill(['status' => 'suspended'])->save();

        $this->assertNotContains('GG-PUN-BNR', array_column($this->getJson('/stations/search')->json('data'), 'code'));
        $this->get('/stations/greengrid-baner-pune')->assertNotFound();
        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_company_staff_only_see_their_own_stations_and_bookings(): void
    {
        $owner = $this->staff($this->greengrid, Role::CompanyOwner);
        $this->actingAs($owner);

        $codes = StationResource::getEloquentQuery()->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['GG-PUN-BNR', 'GG-MUM-AND'], $codes);

        $platformStation = Station::where('code', 'DEL-CP')->first();
        $this->assertFalse($owner->can('update', $platformStation));
        $this->assertFalse($owner->can('changeStatus', $platformStation));
        $this->get("/admin/stations/{$platformStation->slug}/edit")->assertNotFound();
        $this->get('/admin/stations/greengrid-baner-pune/edit')->assertOk();

        // Bookings of another company are invisible
        $customer = User::factory()->create(['phone' => '9876543210']);
        $b = Booking::factory()->forStation($platformStation, $customer)->create();
        $this->assertSame(0, BookingResource::getEloquentQuery()->count());
        $this->assertFalse($owner->can('view', $b));
    }

    public function test_platform_admin_sees_everything_and_onboards_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin);

        $this->assertSame(Station::count(), StationResource::getEloquentQuery()->count());
        $this->get('/admin/companies')->assertOk()->assertSee('GreenGrid');
        $this->get('/admin/companies/create')->assertOk();

        $owner = $this->staff($this->greengrid, Role::CompanyOwner);
        $this->assertFalse($owner->can('create', Company::class));
        $this->assertTrue($owner->can('update', $this->greengrid));
        $this->assertFalse($owner->can('update', $this->platform));
    }

    public function test_staff_management_is_limited_to_own_company(): void
    {
        $owner = $this->staff($this->greengrid, Role::CompanyOwner);
        $mine = $this->staff($this->greengrid, Role::StationManager);
        $theirs = $this->staff($this->platform, Role::Finance);

        $this->assertTrue($owner->can('update', $mine));
        $this->assertFalse($owner->can('update', $theirs));
        $this->assertFalse($owner->can('block', $owner));

        $this->actingAs($owner)->get('/admin/users')->assertOk()->assertSee($mine->email)->assertDontSee($theirs->email);
        $this->assertArrayNotHasKey(Role::SuperAdmin->value, \App\Filament\Resources\Users\UserResource::roleOptions());
    }

    public function test_blog_and_support_are_platform_only(): void
    {
        $owner = $this->staff($this->greengrid, Role::CompanyOwner);
        $this->actingAs($owner)->get('/admin/blog-posts')->assertForbidden();
        $this->actingAs($owner)->get('/admin/contact-messages')->assertForbidden();

        $platformOwner = $this->staff($this->platform, Role::CompanyOwner);
        $this->actingAs($platformOwner)->get('/admin/blog-posts')->assertOk();
    }

    public function test_company_rules_override_the_platform_default(): void
    {
        $gg = Station::where('code', 'GG-PUN-BNR')->first();
        $this->assertNull(RuleSet::activeFor($gg)->company_id); // inherits platform default

        $rules = RuleSet::create(['company_id' => $this->greengrid->id, 'station_id' => null, 'version' => RuleSet::nextVersion($this->greengrid->id, null), 'title' => 'GreenGrid rules', 'content' => '<p>GG</p>']);
        $rules->publish();

        $this->assertSame($rules->id, RuleSet::activeFor($gg)->id);
        $this->assertNull(RuleSet::activeFor(Station::where('code', 'DEL-CP')->first())->company_id); // platform stations unaffected
        $this->assertTrue(RuleSet::whereNull('company_id')->whereNull('station_id')->first()->is_active); // platform default still active

        $owner = $this->staff($this->greengrid, Role::CompanyOwner);
        $this->assertFalse($owner->can('publish', RuleSet::whereNull('company_id')->first()));
        $this->assertTrue($owner->can('publish', $rules));
    }

    public function test_station_manager_cannot_change_station_details(): void
    {
        $station = Station::where('code', 'DEL-CP')->first();
        $manager = $this->staff($this->platform, Role::StationManager);
        $manager->stations()->attach($station);

        $this->assertTrue($manager->can('update', $station)); // opening hours only
        $this->assertFalse($manager->can('update', $station->chargers->first()));
        $this->assertFalse($manager->can('create', \App\Models\Tariff::class));
        $this->assertTrue($manager->can('changeStatus', $station));
    }

    public function test_booking_records_the_operating_company_and_its_gst(): void
    {
        $customer = User::factory()->create(['phone' => '9876543210']);
        $gg = Station::where('code', 'GG-PUN-BNR')->first();
        $charger = $gg->chargers()->where('type', 'DC')->first();

        $this->actingAs($customer)->post("/book/{$gg->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Driver', 'email' => 'd@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '27', 'rule_set_id' => RuleSet::activeFor($gg)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();

        $b = Booking::first();
        $this->assertSame($this->greengrid->id, $b->company_id);
        $this->assertSame('27', $b->supplier_state_code); // GreenGrid's Maharashtra GSTIN
        $this->assertTrue((float) $b->cgst_amount > 0);
        $this->assertSame('19.50', $b->rate_per_kwh);
    }

    public function test_customers_manage_their_vehicles(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($customer)->get('/settings/vehicles')->assertOk();

        $this->actingAs($customer)->post('/settings/vehicles', ['make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'br01 ab 1234', 'battery_kwh' => '40.5', 'connector' => 'ccs2'])
            ->assertSessionHasNoErrors();
        $v = $customer->vehicles()->first();
        $this->assertSame('BR01AB1234', $v->registration_no);
        $this->assertTrue($v->is_default); // first vehicle becomes default

        $this->actingAs($customer)->post('/settings/vehicles', ['make' => 'MG', 'model' => 'ZS EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => '50', 'connector' => 'ccs2'])
            ->assertSessionHasErrors('registration_no'); // duplicate

        $this->actingAs($other)->put("/settings/vehicles/{$v->id}", ['make' => 'X', 'model' => 'Y', 'registration_no' => 'BR02AB1234', 'battery_kwh' => '10', 'connector' => 'ccs2'])->assertForbidden();
        $this->actingAs($other)->delete("/settings/vehicles/{$v->id}")->assertForbidden();

        $this->actingAs($customer)->delete("/settings/vehicles/{$v->id}")->assertRedirect();
        $this->assertSame(0, $customer->vehicles()->count());
    }

    public function test_offline_company_station_cannot_be_booked(): void
    {
        $gg = Station::where('code', 'GG-PUN-BNR')->first();
        app(\App\Services\StationStatusService::class)->change($gg, StationStatus::Offline, 'Grid outage');
        $slots = $this->actingAs(User::factory()->create())->getJson("/book/{$gg->slug}/availability?date=2026-11-25&duration=60")->json('chargers.0.slots');
        $this->assertFalse(collect($slots)->contains('available', true));
    }
}

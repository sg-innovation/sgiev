<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Reports;
use App\Filament\Resources\EnergyCosts\EnergyCostResource;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\StationEnergyCost;
use App\Models\User;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportService;
use App\Support\XlsxWriter;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Phase G: business reports (G1 owner, G2 platform), electricity costs, PDF/Excel downloads and scoping. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Station $gg;

    private Station $patna;

    private const RANGE = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->admin = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $this->admin->assignRole(Role::SuperAdmin->value);
        $this->owner = User::where('email', 'owner@greengrid.test')->firstOrFail();
        $this->gg = Station::where('code', 'GG-PUN-BNR')->firstOrFail();
        $this->patna = Station::where('code', 'PAT-BLY')->firstOrFail();
        // Advanced reports (exports, GST, charger-wise, electricity costs) are a premium feature.
        $this->gg->company->forceFill(['plan_id' => \App\Models\Plan::where('slug', 'premium')->value('id')])->save();
    }

    public function test_free_plan_gets_basic_reports_only(): void
    {
        $this->gg->company->forceFill(['plan_id' => \App\Models\Plan::where('slug', 'free')->value('id')])->save();
        $this->as($this->owner);
        $page = Livewire::test(Reports::class);
        $this->assertSame(['overview', 'stations', 'pending', 'fees'], array_keys($page->instance()->tabs()));
        $page->assertActionHidden('excel')->assertActionHidden('pdf');
        $this->get(EnergyCostResource::getUrl())->assertForbidden();
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

    /** A walk-in at GreenGrid: meter readings give the energy; optionally paid in cash. */
    private function walkIn(string $start, string $stop, bool $pay): Booking
    {
        $connector = $this->gg->chargers()->where('type', 'DC')->first()->connectorPoints()->first();
        $this->as($this->owner)->post('/staff/walk-ins', [
            'connector_id' => $connector->id, 'registration_no' => 'MH12XY'.random_int(1000, 9999), 'make' => 'MG', 'model' => 'ZS EV',
            'name' => 'Walk In Driver', 'phone' => '9876501234', 'minutes' => 60, 'meter_start' => $start, 'informed' => '1',
        ])->assertSessionHasNoErrors();
        $b = Booking::where('source', 'walk_in')->latest('id')->first();
        $this->travel(30)->minutes();
        $s = ChargingSession::where('booking_id', $b->id)->sole();
        $this->post("/staff/sessions/{$s->uuid}/stop", ['meter_stop' => $stop, 'reason' => 'completed'])->assertSessionHasNoErrors();
        if ($pay) {
            $this->post("/staff/bookings/{$b->code}/collect", ['method' => 'cash'])->assertSessionHasNoErrors();
        }

        return $b->fresh();
    }

    /** An online booking at Trans EV Patna, paid through the simulator. */
    private function onlineBooking(): Booking
    {
        $customer = User::factory()->create(['phone' => '9876543210']);
        $customer->assignRole(Role::Customer->value);
        $charger = $this->patna->chargers()->where('type', 'DC')->first();
        $this->as($customer)->post("/book/{$this->patna->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $this->patna->state_code, 'rule_set_id' => RuleSet::activeFor($this->patna)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $payment = $b->payments()->first();
        [, $query] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($query));

        return $b->fresh();
    }

    public function test_platform_report_adds_up_bookings_money_energy_gst_and_profit(): void
    {
        $online = $this->onlineBooking();
        $cash = $this->walkIn('500', '520.5', true);
        $this->walkIn('600', '610', false);
        StationEnergyCost::create(['station_id' => $this->gg->id, 'month' => '2026-11-01', 'cost_per_kwh' => '8', 'fixed_charges' => '3000']);

        $f = ReportFilters::forUser($this->admin, self::RANGE);
        $this->assertSame('day', $f->group);
        $svc = app(ReportService::class);
        $t = $svc->summary($f);

        $this->assertSame([1, 2, 2], [$t['bookings'], $t['walk_ins'], $t['sessions']]);
        $this->assertSame('30.500', $t['kwh']);
        $this->assertSame(number_format((float) $online->total_amount, 2, '.', ''), $t['collected_online']);
        $this->assertSame(number_format((float) $cash->total_amount, 2, '.', ''), $t['collected_station']);
        $this->assertSame(number_format((float) Invoice::sum('taxable_amount'), 2, '.', ''), $t['sales']);
        // Electricity: 30.5 kWh × ₹8 + the month's ₹3,000 fixed charges (whole month in range).
        $this->assertSame('3244.00', $t['energy_cost']);
        $this->assertSame(number_format((float) $t['sales'] - 3244, 2, '.', ''), $t['gross_profit']);

        // Per day, station and operator views add up to the same totals.
        $days = $svc->breakdown($f, 'period');
        $this->assertCount(30, $days['rows']);
        $this->assertSame($t['net_collected'], $days['totals']['net_collected']);
        $byStation = collect($svc->breakdown($f, 'station')['rows'])->keyBy('key');
        $this->assertSame(2, $byStation[(string) $this->gg->id]['walk_ins']);
        $this->assertSame('3244.00', $byStation[(string) $this->gg->id]['energy_cost']);
        $this->assertCount(2, $svc->breakdown($f, 'company')['rows']);
        $this->assertCount(1, $svc->breakdown($f, 'charger')['rows'] ? array_filter($svc->breakdown($f, 'charger')['rows'], fn ($r) => $r['walk_ins'] === 2) : [null, null]);

        // Weekly and monthly grouping.
        $this->assertSame(['2026-11'], array_column($svc->breakdown(ReportFilters::forUser($this->admin, self::RANGE + ['group' => 'month']), 'period')['rows'], 'key'));
        $this->assertSame('2026-11-23', $svc->breakdown(ReportFilters::forUser($this->admin, ['from' => '2026-11-24', 'to' => '2026-11-24', 'group' => 'week']), 'period')['rows'][0]['key']);

        // Pending: the unpaid walk-in.
        $pending = $svc->pending($f);
        $this->assertSame(1, $pending['walk_ins']['count']);

        // GST summary matches the invoices.
        $gst = $svc->gst($f);
        $this->assertSame(Invoice::count(), $gst['totals']['invoices']);
        $this->assertSame(number_format((float) Invoice::sum('taxable_amount'), 2, '.', ''), number_format((float) $gst['totals']['net_taxable'], 2, '.', ''));
    }

    public function test_operators_only_see_their_own_figures(): void
    {
        $this->onlineBooking();
        $this->walkIn('500', '520.5', true);

        $f = ReportFilters::forUser($this->owner, self::RANGE + ['company' => $this->patna->company_id, 'stations' => [$this->patna->id]]);
        $this->assertSame($this->gg->company_id, $f->companyId, 'an operator cannot pick another operator');
        $this->assertSame([0], $f->stationIds, "another operator's station is ignored");
        $t = app(ReportService::class)->summary(ReportFilters::forUser($this->owner, self::RANGE));
        $this->assertSame([0, 1], [$t['bookings'], $t['walk_ins']]);
        $this->assertSame('0.00', $t['collected_online']);

        // The page hides the operators tab for partners, and every visible tab renders.
        $this->as($this->owner);
        $page = Livewire::test(Reports::class, self::RANGE);
        $this->assertArrayNotHasKey('operators', $page->instance()->tabs());
        foreach (array_keys($page->instance()->tabs()) as $tab) {
            Livewire::test(Reports::class)->set('from', '2026-11-01')->set('to', '2026-11-30')->set('tab', $tab)->assertOk();
        }

        // Station managers only see their assigned stations.
        $manager = User::factory()->create(['company_id' => $this->gg->company_id]);
        $manager->assignRole(Role::StationManager->value);
        $this->assertSame([0], ReportFilters::forUser($manager, self::RANGE)->stationIds);
        $manager->stations()->attach($this->gg->id);
        $this->assertSame([$this->gg->id], ReportFilters::forUser($manager->fresh(), self::RANGE)->stationIds);

        // Customers have no access.
        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $this->as($customer)->get(Reports::getUrl())->assertForbidden();
    }

    public function test_reports_download_as_pdf_and_excel(): void
    {
        $this->walkIn('500', '520.5', true);
        $this->as($this->admin)->get(Reports::getUrl(['tab' => 'overview']))->assertOk()->assertSee('Net collected per day');

        Livewire::test(Reports::class)->set('from', '2026-11-01')->set('to', '2026-11-30')
            ->callAction('excel')->assertFileDownloaded('TransEV-report-20261101-20261130.xlsx');
        Livewire::test(Reports::class)->set('from', '2026-11-01')->set('to', '2026-11-30')
            ->callAction('pdf')->assertFileDownloaded('TransEV-report-20261101-20261130.pdf');
        $this->assertDatabaseHas('activity_log', ['description' => 'report downloaded']);

        // Station managers can view but not download.
        $manager = User::factory()->create(['company_id' => $this->gg->company_id]);
        $manager->assignRole(Role::StationManager->value);
        $manager->stations()->attach($this->gg->id);
        $this->as($manager);
        Livewire::test(Reports::class)->assertActionHidden('excel')->assertActionHidden('pdf');
    }

    public function test_excel_writer_keeps_numbers_and_neutralises_formulas(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x');
        (new XlsxWriter)->sheet('Test', [['Name', 'Amount'], ['=HYPERLINK("http://x")', '1234.50'], ['Plain', 7]])->save($path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('<t xml:space="preserve">&apos;=HYPERLINK', $xml);
        $this->assertStringContainsString('<v>1234.50</v>', $xml);
        $this->assertStringContainsString('<v>7</v>', $xml);
    }

    public function test_electricity_costs_are_scoped_to_the_operators_stations(): void
    {
        $this->as($this->owner)->get(EnergyCostResource::getUrl())->assertOk();
        $own = StationEnergyCost::create(['station_id' => $this->gg->id, 'month' => '2026-11-17', 'cost_per_kwh' => '8.5']);
        $this->assertSame('2026-11-01', $own->month->toDateString(), 'saved as the whole month');
        $this->assertSame($this->gg->company_id, $own->company_id);
        $other = StationEnergyCost::create(['station_id' => $this->patna->id, 'month' => '2026-11-01', 'cost_per_kwh' => '9']);

        $this->assertTrue($this->owner->can('update', $own));
        $this->assertFalse($this->owner->can('update', $other));
        $this->assertSame([$own->id], EnergyCostResource::getEloquentQuery()->pluck('id')->all());

        $auditor = User::factory()->create(['company_id' => $this->gg->company_id]);
        $auditor->assignRole(Role::Auditor->value);
        $this->assertTrue($auditor->can('viewAny', StationEnergyCost::class));
        $this->assertFalse($auditor->can('create', StationEnergyCost::class));
    }
}

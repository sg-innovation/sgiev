<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ConnectorLiveStatus;
use App\Enums\Role;
use App\Filament\Pages\Integrations;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Connector;
use App\Models\ConnectorStatusHistory;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ConnectorStatusService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Support\Settings;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class PhaseAFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'PAT-BLY')->first();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(User $user, int $chargerId, ?int $connectorId, string $at = '2026-11-25 12:00', string $reg = 'BR01AB1234')
    {
        return $this->actingAs($user)->post("/book/{$this->station->slug}", [
            'charger_id' => $chargerId, 'connector_id' => $connectorId, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => $reg, 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'name' => 'Driver', 'email' => 'd@example.com', 'phone' => '9876543210',
            'state_code' => '10', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ]);
    }

    public function test_seeded_chargers_have_one_connector_row_per_type(): void
    {
        $dc = $this->station->chargers()->where('type', 'DC')->first();
        $this->assertSame(1, $dc->connectorPoints()->count());
        $this->assertSame(['ccs2'], $dc->connectors);
        $this->assertSame(Connector::count(), \App\Models\Charger::all()->sum(fn ($c) => count($c->connectors)));
    }

    public function test_each_connector_on_a_charger_is_booked_separately(): void
    {
        $dc = $this->station->chargers()->where('type', 'DC')->first();
        $gun2 = $dc->connectorPoints()->create(['type' => 'ccs2']); // second CCS2 gun
        $gun1 = $dc->connectorPoints()->where('connector_no', 1)->first();
        $this->assertSame(2, $gun2->connector_no);

        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        $this->book($a, $dc->id, $gun1->id)->assertSessionHasNoErrors();
        $this->book($b, $dc->id, $gun2->id, reg: 'BR01CD5678')->assertSessionHasNoErrors();
        $this->book($c, $dc->id, $gun1->id, reg: 'BR01EF9999')->assertSessionHasErrors('start'); // gun 1 taken

        $this->assertEqualsCanonicalizing([$gun1->id, $gun2->id], Booking::pluck('connector_id')->all());

        $grid = $this->actingAs($a)->getJson("/book/{$this->station->slug}/availability?date=2026-11-25&duration=60")->json('chargers');
        $this->assertCount(Connector::whereIn('charger_id', $this->station->chargers()->pluck('id'))->count(), $grid);
        $gun2Slots = collect($grid)->firstWhere('connector_id', $gun2->id)['slots'];
        $this->assertSame('Booked', collect($gun2Slots)->firstWhere('label', '12:00 PM')['reason']);
    }

    public function test_faulted_connector_blocks_only_near_term_slots(): void
    {
        $gun = $this->station->chargers()->where('type', 'DC')->first()->connectorPoints()->first();
        app(ConnectorStatusService::class)->setLive($gun, ConnectorLiveStatus::Faulted, 'admin', 'Cable damaged');

        $user = User::factory()->create();
        $this->book($user, $gun->charger_id, $gun->id, '2026-11-24 11:00')->assertSessionHasErrors('start');
        $this->book($user, $gun->charger_id, $gun->id, '2026-11-25 12:00')->assertSessionHasNoErrors();

        $history = ConnectorStatusHistory::first();
        $this->assertSame('faulted', $history->to_status);
        $this->expectException(LogicException::class);
        $history->delete();
    }

    public function test_marketplace_shows_live_gun_counts(): void
    {
        $data = collect($this->getJson('/stations/search')->json('data'))->firstWhere('code', 'PAT-BLY');
        $this->assertSame(4, $data['guns']['total']); // DC CCS2, AC Type 2, and a 2-wheeler charger with two socket types
        $this->assertSame(4, $data['guns']['available']);

        $gun = $this->station->chargers()->first()->connectorPoints()->first();
        app(ConnectorStatusService::class)->setLive($gun, ConnectorLiveStatus::Charging, 'ocpp');
        $data = collect($this->getJson('/stations/search')->json('data'))->firstWhere('code', 'PAT-BLY');
        $this->assertSame(3, $data['guns']['available']);
        $this->assertSame(1, $data['guns']['busy']);
    }

    public function test_connector_board_is_scoped_per_company(): void
    {
        $gg = User::factory()->create(['company_id' => Company::where('slug', 'greengrid-demo')->value('id')]);
        $gg->assignRole(Role::CompanyOwner->value);
        $this->actingAs($gg)->get('/admin/connectors')->assertOk()->assertSee('GG-PUN-BNR')->assertDontSee('PAT-BLY-DC01');
        $this->assertSame(4, \App\Filament\Resources\Connectors\ConnectorResource::getEloquentQuery()->count());
    }

    public function test_integrations_are_super_admin_only_and_secrets_encrypted(): void
    {
        $owner = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $owner->assignRole(Role::CompanyOwner->value);
        $this->actingAs($owner)->get('/admin/integrations')->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($admin)->get('/admin/integrations')->assertOk()->assertDontSee('super-secret-pass');

        Livewire::test(Integrations::class)
            ->fillForm(['mail__mailer' => 'smtp', 'mail__from_address' => 'noreply@transevrecharge.com', 'mail__from_name' => 'Trans EV', 'mail__smtp_host' => 'smtp.example.com', 'mail__smtp_password' => 'super-secret-pass'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = SystemSetting::where('key', 'mail.smtp_password')->first();
        $this->assertTrue($row->is_secret);
        $this->assertNotSame('super-secret-pass', $row->getRawOriginal('value'));
        $this->assertSame('super-secret-pass', Crypt::decryptString($row->getRawOriginal('value')));
        $this->assertSame('super-secret-pass', config('mail.mailers.smtp.password'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));

        // Saving again with an empty secret keeps the stored value; the form never receives it.
        Livewire::test(Integrations::class)->assertFormFieldExists('mail__smtp_password')->call('save');
        $this->assertSame('super-secret-pass', Crypt::decryptString(SystemSetting::where('key', 'mail.smtp_password')->first()->getRawOriginal('value')));
        $this->assertArrayNotHasKey('mail__smtp_password', Settings::formValues());

        $this->assertDatabaseHas('activity_log', ['description' => 'integration settings updated']);
        $this->assertStringNotContainsString('super-secret-pass', (string) \Illuminate\Support\Facades\DB::table('activity_log')->where('description', 'integration settings updated')->value('properties'));
    }

    public function test_operator_and_platform_staff_can_open_receipts_but_other_companies_cannot(): void
    {
        $customer = User::factory()->create(['phone' => '9876543210']);
        $dc = $this->station->chargers()->where('type', 'DC')->first();
        $this->book($customer, $dc->id, null)->assertSessionHasNoErrors();
        $booking = Booking::first();
        $this->actingAs($customer)->post("/account/bookings/{$booking->code}/pay");
        $payment = $booking->payments()->first();
        [, $query] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->actingAs($customer)->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($query));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $finance = User::factory()->create(['company_id' => $this->station->company_id]);
        $finance->assignRole(Role::Finance->value);
        $this->actingAs($finance)->get("/account/bookings/{$booking->code}/receipt")->assertOk();
        $this->actingAs($finance)->get("/admin/bookings/{$booking->code}")->assertOk()->assertSee('View / download receipt');

        $gg = User::factory()->create(['company_id' => Company::where('slug', 'greengrid-demo')->value('id')]);
        $gg->assignRole(Role::CompanyOwner->value);
        $this->actingAs($gg)->get("/account/bookings/{$booking->code}/receipt")->assertForbidden();
    }
}

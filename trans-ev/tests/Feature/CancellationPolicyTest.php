<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\CancellationPolicies\CancellationPolicyResource;
use App\Filament\Resources\CancellationPolicies\Pages\CreateCancellationPolicy;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Company;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\Payments\RefundService;
use App\Services\Payments\SimulatedSabPaisaGateway;
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

/** B3: operator cancellation & no-show policies, agreed at checkout and applied to refunds. */
class CancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Station $patna;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->patna = Station::where('code', 'PAT-BLY')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidBooking(Station $station, string $at = '2026-11-24 16:00'): Booking
    {
        $charger = $station->chargers()->where('type', 'DC')->first() ?? $station->chargers()->first();
        $this->actingAs($this->customer)->post("/book/{$station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => $charger->connectorPoints()->first()->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => $station->state_code, 'rule_set_id' => RuleSet::activeFor($station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->post("/account/bookings/{$b->code}/pay");
        $payment = $b->payments()->first();
        [, $query] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($query));

        return $b->fresh();
    }

    private function ggPolicy(array $over = []): CancellationPolicy
    {
        return CancellationPolicy::create(array_merge([
            'company_id' => Company::where('slug', 'greengrid-demo')->value('id'), 'name' => 'GreenGrid flexible',
            'tiers' => [
                ['min_minutes_before' => 60, 'refund_percent' => 100, 'fee' => 25, 'label' => 'Up to 1 hour before: full refund less ₹25'],
                ['min_minutes_before' => 0, 'refund_percent' => 20, 'fee' => 0, 'label' => 'Later: 20% refund'],
            ],
            'no_show_refund_percent' => 10, 'is_active' => true,
        ], $over));
    }

    public function test_the_trans_ev_default_is_saved_on_each_booking(): void
    {
        $b = $this->paidBooking($this->patna);
        $this->assertNotNull($b->cancellation_policy);
        $this->assertSame('Trans EV standard policy', $b->cancellation_policy['name']);
        $this->assertCount(3, $b->cancellation_policy['tiers']);
        $this->get("/account/bookings/{$b->code}")->assertInertia(fn ($p) => $p->where('booking.cancellationPolicy.name', 'Trans EV standard policy'));
    }

    public function test_operator_and_station_policies_apply_in_order(): void
    {
        $gg = Station::where('code', 'GG-PUN-BNR')->first();
        $company = $this->ggPolicy();
        $this->assertSame($company->id, CancellationPolicy::activeFor($gg)->id);
        $this->assertNull(CancellationPolicy::activeFor($this->patna)->company_id, 'other operators keep the default');

        $station = $this->ggPolicy(['station_id' => $gg->id, 'name' => 'Baner strict']);
        $this->assertSame($station->id, CancellationPolicy::activeFor($gg)->id);

        $this->get("/stations/{$gg->slug}")->assertInertia(fn ($p) => $p->where('cancellationPolicy.name', 'Baner strict'));
    }

    public function test_agreed_policy_is_used_even_after_the_operator_changes_it(): void
    {
        $gg = Station::where('code', 'GG-PUN-BNR')->first();
        $policy = $this->ggPolicy();
        $b = $this->paidBooking($gg, '2026-11-24 16:00'); // 6 hours ahead

        $policy->update(['tiers' => [['min_minutes_before' => 0, 'refund_percent' => 0, 'fee' => 0, 'label' => 'No refunds']]]);
        $this->assertSame(2, $policy->fresh()->version);

        $q = app(RefundService::class)->quote($b->fresh());
        $this->assertSame(100, $q['percent']);
        $this->assertSame(bcsub((string) $b->total_amount, '25.00', 2), $q['refund'], 'fee is taken off the refund');
    }

    public function test_fee_never_makes_the_refund_negative(): void
    {
        $snapshot = ['tiers' => [['min_minutes_before' => 0, 'refund_percent' => 10, 'fee' => '500', 'label' => 'x']]];
        $this->assertSame('0.00', CancellationPolicy::quote($snapshot, '100.00', 30)['refund']);
    }

    public function test_no_show_refund_follows_the_policy(): void
    {
        $gg = Station::where('code', 'GG-PUN-BNR')->first();
        $this->ggPolicy();
        $b = $this->paidBooking($gg, '2026-11-24 12:00');
        $svc = app(RefundService::class);
        $owner = User::where('email', 'owner@greengrid.test')->first();

        try {
            $svc->markNoShow($b, $owner);
            $this->fail('too early');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        Carbon::setTestNow(Carbon::parse('2026-11-24 12:15', 'Asia/Kolkata'));
        $refund = $svc->markNoShow($b->fresh(), $owner);
        $b->refresh();
        $this->assertSame(BookingStatus::NoShow, $b->status);
        $this->assertNotNull($b->no_show_at);
        $this->assertSame(\App\Services\GstService::round(bcdiv(bcmul((string) $b->total_amount, '10', 4), '100', 4)), $refund->amount);
    }

    public function test_staff_mark_no_show_from_the_booking_page(): void
    {
        $b = $this->paidBooking($this->patna, '2026-11-24 12:00');
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:20', 'Asia/Kolkata'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::SuperAdmin->value);
        $this->app['session']->flush();
        $this->actingAs($admin);
        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])->callAction('noShow');
        $this->assertSame(BookingStatus::NoShow, $b->fresh()->status);
        $this->assertNull($b->fresh()->refunds()->first(), 'default policy refunds nothing on a no-show');
    }

    public function test_operators_manage_only_their_own_policies(): void
    {
        $owner = User::where('email', 'owner@greengrid.test')->first();
        $this->app['session']->flush();
        $this->actingAs($owner);

        Livewire::test(CreateCancellationPolicy::class)
            ->fillForm(['name' => 'Mine', 'tiers' => [['min_minutes_before' => 30, 'refund_percent' => 100, 'fee' => 0, 'label' => 'Full']], 'no_show_refund_percent' => 0, 'is_active' => true])
            ->call('create')->assertHasErrors();

        Livewire::test(CreateCancellationPolicy::class)
            ->fillForm(['name' => 'Mine', 'tiers' => [['min_minutes_before' => 30, 'refund_percent' => 100, 'fee' => 0, 'label' => 'Full'], ['min_minutes_before' => 0, 'refund_percent' => 50, 'fee' => 0, 'label' => 'Half']], 'no_show_refund_percent' => 0, 'is_active' => true])
            ->call('create')->assertHasNoErrors();
        $mine = CancellationPolicy::where('name', 'Mine')->first();
        $this->assertSame(Company::where('slug', 'greengrid-demo')->value('id'), $mine->company_id);

        $default = CancellationPolicy::platformDefault();
        $this->get(CancellationPolicyResource::getUrl('edit', ['record' => $default]))->assertNotFound(); // hidden from operators entirely
    }

    public function test_refund_policy_page_shows_the_trans_ev_default(): void
    {
        CancellationPolicy::platformDefault()->update(['tiers' => [
            ['min_minutes_before' => 240, 'refund_percent' => 100, 'fee' => 0, 'label' => 'Full refund (4+ hours before)'],
            ['min_minutes_before' => 0, 'refund_percent' => 25, 'fee' => 10, 'label' => '25% refund (under 4 hours)'],
        ]]);
        $this->get('/refund-policy')->assertInertia(fn ($p) => $p->where('content.sections.2.html', fn ($html) => str_contains(html_entity_decode($html), 'Full refund (4+ hours before)')
            && str_contains($html, '<strong>25%</strong>') && str_contains(html_entity_decode($html), 'cancellation fee')));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\BookingReschedule;
use App\Models\Company;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\RescheduleService;
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

/** C6: customer and operator reschedules within policy, with history; slot conflicts and price guard. */
class RescheduleTest extends TestCase
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

    private function paid(string $at = '2026-11-25 12:00', ?User $user = null): Booking
    {
        $user ??= $this->customer;
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->as($user)->post("/book/{$this->station->slug}", [
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

    private function iso(string $at): string
    {
        return Carbon::parse($at, 'Asia/Kolkata')->toIso8601String();
    }

    public function test_customer_moves_booking_and_history_is_kept(): void
    {
        $b = $this->paid();
        $json = $this->as($this->customer)->getJson("/account/bookings/{$b->code}/reschedule?date=2026-11-25")->assertOk()->json();
        $this->assertNull($json['blocker']);
        $this->assertNotEmpty($json['connectors']);
        $current = collect($json['connectors'])->firstWhere('connectorId', $b->connector_id);
        $this->assertTrue(collect($current['slots'])->contains(fn ($s) => $s['current']), 'own slot listed as current, not booked');

        $this->post("/account/bookings/{$b->code}/reschedule", ['connector_id' => $b->connector_id, 'start' => $this->iso('2026-11-25 15:00')])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('2026-11-25 15:00', $b->start_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame(60, (int) $b->start_at->diffInMinutes($b->end_at));
        $h = BookingReschedule::where('booking_id', $b->id)->sole();
        $this->assertSame('customer', $h->by_role);
        $this->assertSame('2026-11-25 12:00', $h->from_start->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        Notification::assertSentTo($this->customer, BookingNotice::class, fn ($n) => $n->template === 'booking.rescheduled');

        // The old slot is free again; the new one is taken.
        $avail = app(\App\Services\AvailabilityService::class);
        $this->assertTrue($avail->isConnectorFree($b->connectorPoint, Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->utc(), Carbon::parse('2026-11-25 13:00', 'Asia/Kolkata')->utc()));
        $this->assertFalse($avail->isConnectorFree($b->connectorPoint, Carbon::parse('2026-11-25 15:00', 'Asia/Kolkata')->utc(), Carbon::parse('2026-11-25 16:00', 'Asia/Kolkata')->utc()));

        $this->get("/account/bookings/{$b->code}")->assertInertia(fn ($p) => $p->where('booking.reschedule.remaining', 1)->has('booking.history', 1));
    }

    public function test_limits_notice_and_conflicts(): void
    {
        $b = $this->paid();
        $other = $this->paid('2026-11-25 16:00', User::factory()->create());
        $this->assertSame('confirmed', $other->status->value, 'other booking paid');
        $this->assertSame($b->connector_id, $other->connector_id);

        // Taken slot is refused.
        $this->as($this->customer)->post("/account/bookings/{$b->code}/reschedule", ['connector_id' => $other->connector_id, 'start' => $this->iso('2026-11-25 16:00')])->assertSessionHasErrors('start');
        // Two changes allowed, the third refused.
        $this->post("/account/bookings/{$b->code}/reschedule", ['connector_id' => $b->connector_id, 'start' => $this->iso('2026-11-25 13:00')])->assertSessionHasNoErrors();
        $this->post("/account/bookings/{$b->code}/reschedule", ['connector_id' => $b->connector_id, 'start' => $this->iso('2026-11-25 14:00')])->assertSessionHasNoErrors();
        $this->post("/account/bookings/{$b->code}/reschedule", ['connector_id' => $b->connector_id, 'start' => $this->iso('2026-11-25 18:00')])->assertSessionHasErrors('start');

        // Too close to the start.
        $c = $this->paid('2026-11-24 11:00');
        $this->travelTo(Carbon::parse('2026-11-24 10:05', 'Asia/Kolkata'));
        $this->as($this->customer)->post("/account/bookings/{$c->code}/reschedule", ['connector_id' => $c->connector_id, 'start' => $this->iso('2026-11-25 19:00')])->assertSessionHasErrors('start');
        // Someone else's booking.
        $this->as(User::factory()->create())->post("/account/bookings/{$c->code}/reschedule", ['connector_id' => $c->connector_id, 'start' => $this->iso('2026-11-25 19:00')])->assertSessionHasErrors('start');
        $this->assertSame(2, BookingReschedule::count());
    }

    public function test_customer_cannot_move_into_a_dearer_slot(): void
    {
        $b = $this->paid();
        // Make 18:00–22:00 a peak band costing more.
        $tariff = $b->tariff;
        $tariff->forceFill(['time_bands' => [['name' => 'Evening peak', 'from' => '18:00', 'to' => '22:00', 'rate_per_kwh' => (float) $tariff->rate_per_kwh * 2]]])->save();
        $this->as($this->customer)->post("/account/bookings/{$b->code}/reschedule", ['connector_id' => $b->connector_id, 'start' => $this->iso('2026-11-25 19:00')])
            ->assertSessionHasErrors('start');
        $this->assertSame(0, BookingReschedule::count());
    }

    public function test_operator_moves_booking_from_admin_any_time_before_start(): void
    {
        $b = $this->paid('2026-11-24 11:00'); // within the customer's notice window
        $this->as($this->owner)->get(\App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $b]))->assertOk();
        $other = $this->station->chargers()->where('type', 'DC')->first()->connectorPoints()->first();
        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->callAction('reschedule', ['date' => '2026-11-24', 'slot' => $other->id.'|'.$this->iso('2026-11-24 14:00'), 'reason' => 'Charger under repair'])
            ->assertHasNoActionErrors();
        $b->refresh();
        $this->assertSame('2026-11-24 14:00', $b->start_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('operator', BookingReschedule::sole()->by_role);
        $this->assertSame('Charger under repair', BookingReschedule::sole()->reason);

        // Staff of another company cannot.
        $stranger = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $stranger->assignRole(Role::StationManager->value);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RescheduleService::class)->reschedule($b, $other, Carbon::parse('2026-11-24 16:00', 'Asia/Kolkata'), $stranger, true);
    }
}

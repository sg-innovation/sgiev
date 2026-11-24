<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Services\PricingService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** B4: peak / off-peak energy rates and parking charges, itemised on the estimate, booking and invoice. */
class PricingV2Test extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata')); // Tuesday
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
        $this->station = Station::where('code', 'PAT-BLY')->first();
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->tariff = app(PricingService::class)->tariffFor($this->station, $charger);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function estimate(string $start, int $duration = 60): array
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();

        return app(PricingService::class)->estimate($this->station, $charger, '40', 20, 80, $duration, Carbon::parse($start, 'Asia/Kolkata')->utc());
    }

    private function band(string $name, string $from, string $to, float $rate, array $days = []): array
    {
        return ['name' => $name, 'days' => $days, 'from' => $from, 'to' => $to, 'rate_per_kwh' => $rate];
    }

    public function test_no_bands_keeps_a_single_energy_line(): void
    {
        $e = $this->estimate('2026-11-25 12:00');
        $energy = collect($e['lines'])->where('type', 'energy');
        $this->assertCount(1, $energy);
        $this->assertSame('Energy', $energy->first()['label']);
        $this->assertSame((string) $this->tariff->rate_per_kwh, $energy->first()['rate']);
    }

    public function test_slot_inside_a_peak_band_uses_the_band_rate(): void
    {
        $this->tariff->update(['time_bands' => [$this->band('Peak', '12:00', '14:00', 30)]]);
        $e = $this->estimate('2026-11-25 12:00');
        $line = collect($e['lines'])->firstWhere('type', 'energy');
        $this->assertSame('Energy · Peak', $line['label']);
        $this->assertSame('30.00', $line['rate']);
        $this->assertSame(\App\Services\GstService::round(bcmul($e['kwh'], '30', 6)), $e['energy_amount']);
    }

    public function test_slot_across_two_bands_is_split_by_minutes(): void
    {
        $this->tariff->update(['time_bands' => [$this->band('Peak', '12:30', '14:00', 30)]]);
        $e = $this->estimate('2026-11-25 12:00');
        $energy = collect($e['lines'])->where('type', 'energy')->values();
        $this->assertCount(2, $energy);
        $this->assertSame([30, 30], $energy->pluck('minutes')->all());
        $this->assertSame($e['kwh'], bcadd($energy[0]['qty'], $energy[1]['qty'], 3), 'kWh shares add up exactly');
        $this->assertSame($e['energy_amount'], bcadd($energy[0]['amount'], $energy[1]['amount'], 2));
        $this->assertSame($e['subtotal_amount'], bcadd($e['energy_amount'], (string) $this->tariff->session_fee, 2));
    }

    public function test_overnight_bands_and_day_filters(): void
    {
        $this->tariff->update(['time_bands' => [
            $this->band('Night', '22:00', '06:00', 10),
            $this->band('Weekday peak', '18:00', '22:00', 28, [1, 2, 3, 4, 5]),
        ]]);
        $t = $this->tariff->fresh();
        $this->assertSame('Night', $t->bandAt(Carbon::parse('2026-11-25 23:30', 'Asia/Kolkata'))['name']);
        $this->assertSame('Night', $t->bandAt(Carbon::parse('2026-11-26 01:00', 'Asia/Kolkata'))['name']);
        $this->assertSame('Weekday peak', $t->bandAt(Carbon::parse('2026-11-25 19:00', 'Asia/Kolkata'))['name']); // Wednesday
        $this->assertNull($t->bandAt(Carbon::parse('2026-11-29 19:00', 'Asia/Kolkata')), 'Sunday is not a weekday peak');
        $this->assertNull($t->bandAt(Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')));
    }

    public function test_parking_is_itemised_and_taxed(): void
    {
        $this->tariff->update(['parking_fee' => 10, 'parking_fee_per_hour' => 20]);
        $e = $this->estimate('2026-11-25 12:00', 60);
        $parking = collect($e['lines'])->firstWhere('type', 'parking');
        $this->assertSame('30.00', $parking['amount']);
        $this->assertSame('996743', $parking['sac']);
        $this->assertSame('30.00', $e['parking_amount']);
        $this->assertSame(bcadd(bcadd($e['energy_amount'], (string) $this->tariff->session_fee, 2), '30.00', 2), $e['subtotal_amount']);
        $this->assertSame($e['taxable_amount'], $e['subtotal_amount']);
    }

    public function test_estimate_endpoint_prices_the_chosen_slot(): void
    {
        $this->tariff->update(['time_bands' => [$this->band('Peak', '12:00', '14:00', 30)]]);
        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $body = ['charger_id' => $charger->id, 'battery_kwh' => 40, 'battery_from' => 20, 'battery_to' => 80, 'duration' => 60];

        $without = $this->actingAs($customer)->postJson("/book/{$this->station->slug}/estimate", $body)->assertOk()->json();
        $this->assertTrue($without['time_of_day']);
        $with = $this->postJson("/book/{$this->station->slug}/estimate", $body + ['start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String()])->json();
        $this->assertSame('Energy · Peak', $with['lines'][0]['label']);
        $this->assertGreaterThan($without['total_amount'], $with['total_amount']);
    }

    public function test_booking_and_invoice_carry_the_lines(): void
    {
        $this->tariff->update(['time_bands' => [$this->band('Peak', '12:30', '14:00', 30)], 'parking_fee' => 15]);
        $customer = User::factory()->create(['phone' => '9876543210']);
        $customer->assignRole(Role::Customer->value);
        $charger = $this->station->chargers()->where('type', 'DC')->first();
        $this->actingAs($customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Test Driver', 'email' => 'driver@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '10', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->assertCount(4, $b->price_lines); // standard energy, peak energy, booking fee, parking
        $this->assertSame('15.00', (string) $b->parking_amount);

        $this->post("/account/bookings/{$b->code}/pay");
        $payment = $b->payments()->first();
        [, $q] = SimulatedSabPaisaGateway::complete($payment, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$payment->uuid}?".http_build_query($q));

        $invoice = $b->fresh()->invoice;
        $this->assertCount(4, $invoice->lines);
        $this->assertSame('996743', collect($invoice->lines)->last()['sac']);
        $this->assertSame((string) $b->total_amount, (string) $invoice->total);
        $this->get("/account/bookings/{$b->code}")->assertInertia(fn ($p) => $p->has('booking.amounts.lines', 4));
    }
}

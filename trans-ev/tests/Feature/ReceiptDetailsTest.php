<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use App\Support\IndianNumber;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Receipt contents (charging point, electrical rating, payment references) and the no-payment case. */
class ReceiptDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
        $this->customer = User::factory()->create();
        $this->customer->assignRole(Role::Customer->value);
        $station = Station::where('code', 'PAT-BLY')->first();
        $this->booking = Booking::factory()->forStation($station, $this->customer)->create(['status' => BookingStatus::Confirmed]);
    }

    public function test_receipt_without_a_payment_redirects_with_a_message(): void
    {
        $this->actingAs($this->customer)->get(route('bookings.receipt', $this->booking))
            ->assertRedirect(route('bookings.show', $this->booking))
            ->assertSessionHas('error');
    }

    public function test_receipt_shows_charging_point_and_payment_references(): void
    {
        $this->booking->charger->update([
            'ocpp_identity' => 'TEV-PAT-DC01', 'input_voltage_v' => 415, 'phases' => 3, 'output_voltage_min_v' => 150,
            'output_voltage_max_v' => 1000, 'max_current_a' => 200, 'meter_serial' => 'MTR-778', 'meter_accuracy_class' => 'Class 0.5S',
        ]);
        DemoActivitySeeder::demoPayment($this->booking);

        $this->actingAs($this->customer)->get(route('bookings.receipt', $this->booking))->assertOk()
            ->assertInertia(fn ($p) => $p->component('booking/receipt')
                ->where('receipt.chargePoint.ocppId', 'TEV-PAT-DC01')
                ->where('receipt.chargePoint.meterSerial', 'MTR-778')
                ->where('receipt.chargePoint.electrical', fn ($v) => str_contains($v, '415 V 3-phase') && str_contains($v, '200 A max'))
                ->where('receipt.payment.rrn', fn ($v) => strlen((string) $v) === 12)
                ->where('receipt.payment.amountInWords', fn ($v) => str_starts_with($v, 'Rupees ') && str_ends_with($v, ' Only')));
        $this->assertSame(1, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_amount_in_words_uses_indian_numbering(): void
    {
        $this->assertSame('Rupees Three Hundred Seventy Six and Sixty Six Paise Only', IndianNumber::rupeesInWords('376.66'));
        $this->assertSame('Rupees Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven Only', IndianNumber::rupeesInWords('1234567'));
        $this->assertSame('Rupees One Crore Only', IndianNumber::rupeesInWords('10000000'));
    }

    public function test_ac_charger_summary(): void
    {
        $c = new Charger(['type' => 'AC', 'input_voltage_v' => 230, 'phases' => 1, 'max_current_a' => 32]);
        $this->assertSame('230 V 1-phase · 32 A max', $c->electricalSummary());
    }
}

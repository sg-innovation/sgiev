<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\StationStatus;
use App\Models\Booking;
use App\Models\ContactMessage;
use App\Models\RuleAcceptance;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\GstService;
use App\Services\StationStatusService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed "now": 24 Nov 2026, 10:00 IST.
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->station = Station::where('code', 'PAT-BLY')->first(); // Patna, 06:00–23:00, same state as the company GSTIN
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();

        return array_merge([
            'charger_id' => $charger->id,
            'start' => Carbon::parse('2026-11-25 12:00', 'Asia/Kolkata')->toIso8601String(),
            'duration' => 60,
            'make' => 'Tata',
            'model' => 'Nexon EV',
            'registration_no' => 'BR01 AB 1234',
            'battery_kwh' => 40,
            'connector' => 'ccs2',
            'battery_from' => 20,
            'battery_to' => 80,
            'save_vehicle' => true,
            'name' => 'Test Driver',
            'email' => 'driver@example.com',
            'phone' => '9876543210',
            'gstin' => '',
            'state_code' => '10',
            'rule_set_id' => RuleSet::activeFor($this->station)->id,
            'accept_rules' => true,
        ], $overrides);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get("/book/{$this->station->slug}")->assertRedirect('/login');
    }

    public function test_wizard_and_availability_render(): void
    {
        $this->actingAs($this->customer)->get("/book/{$this->station->slug}")->assertOk();

        $res = $this->actingAs($this->customer)->getJson("/book/{$this->station->slug}/availability?date=2026-11-25&duration=60")->assertOk();
        $slots = $res->json('chargers.0.slots');
        $this->assertSame('6:00 AM', $slots[0]['label']);
        $this->assertSame('10:00 PM', end($slots)['label']); // last 60-min slot before 23:00 close
        $this->assertTrue($slots[0]['available']);

        // Today: slots before now + lead time are unavailable.
        $today = $this->getJson("/book/{$this->station->slug}/availability?date=2026-11-24&duration=60")->json('chargers.0.slots');
        $this->assertSame('Past', collect($today)->firstWhere('label', '9:00 AM')['reason']);

        // Outside the 7-day window is rejected.
        $this->getJson("/book/{$this->station->slug}/availability?date=2026-12-10&duration=60")->assertUnprocessable();
    }

    public function test_customer_can_hold_a_slot_with_rules_acceptance_and_gst(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload())->assertRedirect();

        $b = Booking::firstOrFail();
        $this->assertSame(BookingStatus::Held, $b->status);
        $this->assertTrue($b->hold_expires_at->equalTo(now()->addMinutes(\App\Support\BusinessRules::int('booking.hold_minutes'))));
        $this->assertSame('BR01AB1234', $b->vehicle_snapshot['registration_no']);
        $this->assertMatchesRegularExpression('/^TEV-261124-[A-Z2-9]{5}$/', $b->code);

        // 40 kWh × 60% / 0.9 = 26.667 kWh × ₹21 = 560.01 + ₹20 fee = 580.01; Bihar → Bihar = CGST + SGST 9% each.
        $this->assertSame('26.667', $b->est_kwh);
        $this->assertSame('580.01', $b->taxable_amount);
        $this->assertSame('52.20', $b->cgst_amount);
        $this->assertSame('52.20', $b->sgst_amount);
        $this->assertSame('0.00', $b->igst_amount);
        $this->assertSame('684.41', $b->total_amount);

        $acc = RuleAcceptance::firstOrFail();
        $this->assertSame($b->id, $acc->booking_id);
        $this->assertSame(hash('sha256', \App\Support\PublicHtml::clean(RuleSet::find($acc->rule_set_id)->content)), $acc->content_hash);
        $this->assertSame($this->station->company_id, $b->company_id);
        $this->assertSame(1, $this->customer->vehicles()->count());

        $this->get("/account/bookings/{$b->code}")->assertOk();
    }

    public function test_gst_uses_a_registration_in_the_stations_state(): void
    {
        $gst = app(GstService::class);
        $delhi = Station::where('code', 'DEL-CP')->first(); // company GSTIN is Bihar (10), station in Delhi (07)

        // Bihar GSTIN cannot invoice a Delhi station: no invoice GSTIN yet, and the supply stays intra-state (CGST+SGST).
        $this->assertNull($gst->invoiceGstin($delhi));
        $split = $gst->split('100.00', '18.00', $gst->supplierStateCode($delhi), $delhi->state_code);
        $this->assertTrue($split['intra_state']);
        $this->assertSame('9.00', $split['cgst']);

        // A GSTIN from another state on the station is ignored.
        $delhi->forceFill(['gstin' => '27AANCT1689C1ZX'])->save();
        $this->assertNull($gst->invoiceGstin($delhi->fresh()));

        $delhi->forceFill(['gstin' => '07AANCT1689C1ZX'])->save();
        $this->assertSame('07AANCT1689C1ZX', $gst->invoiceGstin($delhi->fresh()));

        // Patna station: the company's Bihar GSTIN applies.
        $this->assertSame('10AANCT1689C1ZG', $gst->invoiceGstin($this->station));
    }

    public function test_off_grid_start_times_and_bad_numbers_are_rejected(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload([
            'start' => Carbon::parse('2026-11-25 12:07:31', 'Asia/Kolkata')->toIso8601String(),
        ]))->assertSessionHasErrors('start');

        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload(['battery_kwh' => '1e1']))
            ->assertSessionHasErrors('battery_kwh');
        $this->assertSame(0, Booking::count());
    }

    public function test_price_uses_the_tariff_in_force_at_the_slot_time(): void
    {
        $dc = $this->station->tariffs()->where('charger_type', 'DC')->first();
        $dc->update(['effective_to' => Carbon::parse('2026-11-26 00:00', 'Asia/Kolkata')]);
        $this->station->tariffs()->create([
            'charger_type' => 'DC', 'name' => 'DC from 26 Nov', 'rate_per_kwh' => '30.00', 'session_fee' => '20.00', 'idle_fee_per_min' => '2.00',
            'idle_grace_minutes' => 10, 'gst_rate' => '18.00', 'sac_code' => '998714', 'effective_from' => Carbon::parse('2026-11-26 00:00', 'Asia/Kolkata'),
        ]);

        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload([
            'start' => Carbon::parse('2026-11-27 12:00', 'Asia/Kolkata')->toIso8601String(),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('30.00', Booking::first()->rate_per_kwh);
    }

    public function test_blocked_and_unverified_users_cannot_book(): void
    {
        $this->customer->forceFill(['blocked_at' => now()])->save();
        $this->actingAs($this->customer)->get("/book/{$this->station->slug}")->assertRedirect('/login');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get("/book/{$this->station->slug}")->assertRedirect('/verify-email');
    }

    public function test_24x7_station_offers_the_last_slot_of_the_day(): void
    {
        $cp = Station::where('code', 'DEL-CP')->first();
        $slots = $this->actingAs($this->customer)->getJson("/book/{$cp->slug}/availability?date=2026-11-25&duration=60")->json('chargers.0.slots');
        $this->assertCount(24, $slots);
        $this->assertSame('11:00 PM', end($slots)['label']);
        $this->assertTrue(end($slots)['available']);
    }

    public function test_same_slot_cannot_be_double_booked(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload())->assertSessionHasNoErrors();

        $other = User::factory()->create();
        $this->actingAs($other)->post("/book/{$this->station->slug}", $this->payload(['registration_no' => 'BR01CD5678']))
            ->assertSessionHasErrors('start');

        // Overlapping start (12:30 with a 60-min booking + 10-min buffer) is also blocked.
        $this->actingAs($other)->post("/book/{$this->station->slug}", $this->payload([
            'registration_no' => 'BR01CD5678',
            'start' => Carbon::parse('2026-11-25 12:30', 'Asia/Kolkata')->toIso8601String(),
        ]))->assertSessionHasErrors('start');

        $this->assertSame(1, Booking::count());
    }

    public function test_expired_hold_releases_the_slot(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload());
        Carbon::setTestNow(now()->addMinutes(11));

        $this->assertSame(1, app(BookingService::class)->expireHolds());
        $this->assertSame(BookingStatus::Expired, Booking::first()->status);

        $other = User::factory()->create();
        $this->actingAs($other)->post("/book/{$this->station->slug}", $this->payload(['registration_no' => 'BR01CD5678']))->assertSessionHasNoErrors();
    }

    public function test_rules_must_be_accepted_and_current(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload(['accept_rules' => false]))->assertSessionHasErrors('accept_rules');

        $old = RuleSet::activeFor($this->station);
        $new = RuleSet::create(['station_id' => null, 'version' => 2, 'title' => 'Rules', 'content' => '<p>Updated rules</p>']);
        $new->publish();

        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload(['rule_set_id' => $old->id]))->assertSessionHasErrors('rules');
        $this->assertSame(0, Booking::count());
    }

    public function test_closed_maintenance_and_wrong_connector_are_rejected(): void
    {
        // 23:30 is after closing.
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload([
            'start' => Carbon::parse('2026-11-25 22:30', 'Asia/Kolkata')->toIso8601String(),
        ]))->assertSessionHasErrors('start');

        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload(['connector' => 'type2']))->assertSessionHasErrors('connector');

        app(StationStatusService::class)->change($this->station, StationStatus::Maintenance, 'Upgrade', Carbon::parse('2026-11-26 00:00', 'Asia/Kolkata'));
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload())->assertSessionHasErrors('start');

        // After the maintenance window ends, booking is allowed again.
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload([
            'start' => Carbon::parse('2026-11-26 12:00', 'Asia/Kolkata')->toIso8601String(),
        ]))->assertSessionHasNoErrors();
    }

    public function test_customers_only_see_and_cancel_their_own_bookings(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload());
        $b = Booking::first();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/account/bookings/{$b->code}")->assertForbidden();
        $this->actingAs($stranger)->post("/account/bookings/{$b->code}/cancel")->assertForbidden();

        $this->actingAs($this->customer)->post("/account/bookings/{$b->code}/cancel")->assertRedirect();
        $this->assertSame(BookingStatus::Cancelled, $b->fresh()->status);
    }

    public function test_rule_acceptances_are_append_only(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload());
        $this->expectException(LogicException::class);
        RuleAcceptance::first()->delete();
    }

    public function test_hours_and_availability_service_durations(): void
    {
        $this->assertSame([60, 120, 180], app(AvailabilityService::class)->durations($this->station));
    }

    public function test_contact_form_saves_query_and_blocks_bots(): void
    {
        $this->post('/contact', [
            'topic' => 'payment', 'name' => 'Asha', 'email' => 'asha@example.com', 'phone' => '9876543210',
            'subject' => 'Invoice copy', 'message' => 'Please resend my GST invoice for last week.', 'consent' => true,
        ])->assertSessionHas('success');
        $this->assertSame(1, ContactMessage::count());
        $this->assertStringStartsWith('TEV-Q', ContactMessage::first()->reference);

        $this->post('/contact', [
            'topic' => 'other', 'name' => 'Bot', 'email' => 'bot@example.com', 'subject' => 'Buy now',
            'message' => 'Spam spam spam spam', 'consent' => true, 'website' => 'http://spam.example',
        ]);
        $this->assertSame(1, ContactMessage::count());

        $this->post('/contact', ['topic' => 'other', 'name' => '', 'email' => 'x', 'subject' => '', 'message' => 'short'])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message', 'consent']);
    }

    public function test_info_pages_render(): void
    {
        foreach (['about', 'contact', 'pricing', 'safety', 'terms', 'privacy', 'refund-policy'] as $page) {
            $this->get("/{$page}")->assertOk();
        }
    }

    public function test_admin_booking_and_inbox_screens(): void
    {
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload());
        $this->post('/contact', ['topic' => 'booking', 'name' => 'A', 'email' => 'a@example.com', 'subject' => 'Help me', 'message' => 'Question about my booking.', 'consent' => true]);

        $ops = User::factory()->create(['company_id' => $this->station->company_id]);
        $ops->assignRole(Role::OperationsAdmin->value);
        $b = Booking::first();
        $this->actingAs($ops)->get('/admin/bookings')->assertOk()->assertSee($b->code);
        $this->actingAs($ops)->get("/admin/bookings/{$b->code}")->assertOk();
        $this->actingAs($ops)->get('/admin/contact-messages')->assertOk();
        $this->actingAs($ops)->get('/admin/contact-messages/'.ContactMessage::first()->id)->assertOk();
        $this->actingAs($this->customer)->get('/dashboard')->assertOk();
        $this->actingAs($this->customer)->get('/account/bookings')->assertOk();
    }
}

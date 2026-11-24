<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\CheckInDesk;
use App\Models\Booking;
use App\Models\CheckInRecord;
use App\Models\Company;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Services\CheckInService;
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

/** C1: signed short-lived booking QR, staff scan, charger sticker scan, desk, and the check-in window. */
class CheckInTest extends TestCase
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

    private function paid(string $at = '2026-11-24 12:00'): Booking
    {
        $charger = $this->station->chargers()->first();
        $this->as($this->customer)->post("/book/{$this->station->slug}", [
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

    public function test_customer_pass_is_a_signed_short_lived_qr(): void
    {
        $b = $this->paid();
        $json = $this->as($this->customer)->getJson("/account/bookings/{$b->code}/pass")->assertOk()->json();
        $this->assertTrue($json['available']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $json['qr']);
        $this->assertStringContainsString('Too early', $json['blocker']);

        // Someone else can't fetch it.
        $other = User::factory()->create();
        $this->as($other)->getJson("/account/bookings/{$b->code}/pass")->assertForbidden();

        // Tokens expire and can't be forged.
        $svc = app(CheckInService::class);
        $token = $svc->token($b)['token'];
        $this->assertSame($b->id, $svc->verify($token)->id);
        $this->travel(6)->minutes();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->verify($token);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $b = $this->paid();
        [$id, $exp, $sig] = explode('.', app(CheckInService::class)->token($b)['token']);
        $forged = $id.'.'.base_convert((string) now()->addDay()->getTimestamp(), 10, 36).'.'.$sig;
        $this->as($this->owner)->get("/checkin/{$forged}")->assertOk()->assertInertia(fn ($p) => $p->component('checkin/confirm')->where('error', 'This check-in code is not valid.'));
    }

    public function test_staff_scan_checks_the_customer_in_within_the_window(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 11:50', 'Asia/Kolkata'));
        $token = app(CheckInService::class)->token($b)['token'];

        $this->as($this->owner)->get("/checkin/{$token}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('checkin/confirm')->where('booking.code', $b->code)->where('booking.blocker', null)->where('booking.registration', 'MH12AB1234'));
        $this->post("/checkin/{$token}")->assertRedirect();

        $b->refresh();
        $this->assertSame('checked_in', $b->status->value);
        $rec = CheckInRecord::where('booking_id', $b->id)->sole();
        $this->assertSame('staff_scan', $rec->method);
        $this->assertSame($this->owner->id, $rec->checked_in_by);
        $this->assertSame(-10, $rec->late_minutes);
        $this->assertSame('preparing', $b->connectorPoint->fresh()->live_status->value);

        // Scanning again is harmless.
        $this->post("/checkin/{$token}")->assertRedirect();
        $this->assertSame(1, CheckInRecord::count());
    }

    public function test_too_early_and_staff_of_other_companies_are_refused(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $token = app(CheckInService::class)->token($b)['token'];
        $this->as($this->owner)->post("/checkin/{$token}")->assertSessionHasErrors('booking');
        $this->assertSame('confirmed', $b->fresh()->status->value);

        $otherStaff = User::where('email', 'like', '%@transevrecharge.test')->first()
            ?? User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $otherStaff->forceFill(['company_id' => Company::where('is_platform', true)->value('id')])->save();
        $otherStaff->syncRoles([Role::StationManager->value]);
        $this->travelTo(Carbon::parse('2026-11-24 12:05', 'Asia/Kolkata'));
        $token = app(CheckInService::class)->token($b)['token'];
        $this->as($otherStaff)->get("/checkin/{$token}")->assertForbidden();

        // The customer scanning their own pass is just sent back to the booking.
        $this->as($this->customer)->get("/checkin/{$token}")->assertRedirect(route('bookings.show', $b));
    }

    public function test_customer_scans_the_charger_sticker(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $charger = $b->charger;
        $this->assertNotNull($charger->qr_key);
        $this->assertArrayNotHasKey('qr_key', $charger->toArray(), 'never exposed in JSON');
        $this->travelTo(Carbon::parse('2026-11-24 12:03', 'Asia/Kolkata'));

        $this->as($this->customer)->get("/charge/{$charger->qr_key}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('checkin/charger')->where('bookings.0.code', $b->code)->where('bookings.0.thisCharger', true));

        // Wrong charger at the same station is refused.
        $other = $this->station->chargers()->whereKeyNot($charger->id)->first();
        if ($other) {
            $this->post("/charge/{$other->qr_key}", ['booking' => $b->code])->assertSessionHasErrors('booking');
        }

        $this->post("/charge/{$charger->qr_key}", ['booking' => $b->code])->assertRedirect(route('bookings.show', $b));
        $this->assertSame('charger_scan', CheckInRecord::where('booking_id', $b->id)->value('method'));
        $this->assertSame(3, CheckInRecord::where('booking_id', $b->id)->value('late_minutes'));

        // Guests must sign in; unknown keys are 404.
        $this->app['session']->flush();
        auth()->logout();
        $this->get("/charge/{$charger->qr_key}")->assertRedirect('/login');
        $this->as($this->customer)->get('/charge/not-a-real-key')->assertNotFound();
    }

    public function test_desk_checks_in_by_code_and_sticker_prints_for_staff_only(): void
    {
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 12:10', 'Asia/Kolkata'));
        $this->as($this->owner)->get(CheckInDesk::getUrl())->assertOk()->assertSee($b->code);
        Livewire::test(CheckInDesk::class)->set('scan', strtolower($b->code))->call('submitScan')->assertNotified();
        $this->assertSame('checked_in', $b->fresh()->status->value);
        $this->assertSame('manual', CheckInRecord::where('booking_id', $b->id)->value('method'));

        $this->get(route('chargers.sticker', $b->charger))->assertOk()->assertSee('Scan to check in')->assertSee($b->charger->code);
        $this->as($this->customer)->get(route('chargers.sticker', $b->charger))->assertForbidden();
        $this->as($this->customer)->get(CheckInDesk::getUrl())->assertForbidden();
    }

    public function test_unpaid_or_finished_bookings_cannot_check_in(): void
    {
        $svc = app(CheckInService::class);
        $b = $this->paid('2026-11-24 12:00');
        $this->travelTo(Carbon::parse('2026-11-24 13:05', 'Asia/Kolkata'));
        $this->assertStringContainsString('ended', $svc->blocker($b->fresh()));
        $b->forceFill(['status' => 'held'])->save();
        $this->assertSame('This booking has not been paid yet.', $svc->blocker($b));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Waitlist\WaitlistResource;
use App\Models\Booking;
use App\Models\Connector;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\BookingNotice;
use App\Services\BookingService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Services\WaitlistService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** C7: waiting list — join when full, queue place, offer as a held booking, pass on if unpaid, book when paid. */
class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Connector $dc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
        $this->dc = $this->station->chargers()->where('type', 'DC')->first()->connectorPoints()->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(): User
    {
        $u = User::factory()->create(['phone' => '9876543210']);
        $u->assignRole(Role::Customer->value);

        return $u;
    }

    private function as(User $user): static
    {
        $this->app['session']->flush();

        return $this->actingAs($user);
    }

    private function book(User $u, string $at = '2026-11-24 12:00'): Booking
    {
        $this->as($u)->post("/book/{$this->station->slug}", [
            'charger_id' => $this->dc->charger_id, 'start' => Carbon::parse($at, 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'MH12AB1234', 'battery_kwh' => 40, 'connector' => $this->dc->type->value,
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false, 'name' => 'Driver', 'email' => 'd@example.com',
            'phone' => '9876543210', 'gstin' => '', 'state_code' => '27', 'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ])->assertSessionHasNoErrors();

        return Booking::latest('id')->first();
    }

    private function pay(User $u, Booking $b): void
    {
        $this->as($u)->post("/account/bookings/{$b->code}/pay");
        $p = $b->payments()->latest('id')->first();
        [, $q] = SimulatedSabPaisaGateway::complete($p, 'SUCCESS');
        $this->get("/payments/sabpaisa/return/{$p->uuid}?".http_build_query($q));
    }

    private function join(User $u, array $extra = [])
    {
        return $this->as($u)->post("/stations/{$this->station->slug}/waitlist", array_merge([
            'date' => '2026-11-24', 'window_from' => '12:00', 'window_to' => '12:30', 'duration' => 60, 'connector' => $this->dc->type->value,
            'make' => 'MG', 'model' => 'ZS EV', 'registration_no' => 'MH14CD5678', 'battery_kwh' => 50, 'battery_from' => 20, 'battery_to' => 80,
            'name' => 'Waiter', 'email' => 'w@example.com', 'phone' => '9876501111', 'state_code' => '27', 'accept_rules' => '1',
        ], $extra));
    }

    public function test_full_flow_offer_pass_on_and_book(): void
    {
        $holder = $this->customer();
        $taken = $this->book($holder);
        $this->pay($holder, $taken);

        $first = $this->customer();
        $second = $this->customer();
        $this->join($first)->assertSessionHasNoErrors()->assertRedirect(route('waitlist.index'));
        $this->join($second)->assertSessionHasNoErrors();
        [$e1, $e2] = WaitlistEntry::orderBy('id')->get()->all();
        $svc = app(WaitlistService::class);
        $this->assertSame(1, $svc->position($e1));
        $this->assertSame(2, $svc->position($e2));
        $this->assertNotNull($svc->estimate($e1));

        $this->assertSame(0, $svc->match(), 'still full');

        // The slot frees up: first in line gets it as a held booking.
        app(\App\Services\Payments\RefundService::class)->cancel($taken->fresh(), 'Changed plans', $holder, false);
        $this->assertSame(1, $svc->match());
        $e1->refresh();
        $this->assertSame('offered', $e1->status);
        $offer = $e1->booking;
        $this->assertSame('held', $offer->status->value);
        $this->assertSame($first->id, $offer->user_id);
        $this->assertSame('MH14CD5678', $offer->vehicle_snapshot['registration_no']);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $offer->hold_expires_at->getTimestamp(), 2);
        Notification::assertSentTo($first, BookingNotice::class, fn ($n) => $n->template === 'waitlist.offer');
        $this->assertSame(1, $svc->position($e2->fresh()), 'second moves up');
        $this->as($first)->get('/account/waitlist')->assertInertia(fn ($p) => $p->component('waitlist/index')->where('entries.0.offer.code', $offer->code));

        // Not paid in time → passed on to the next customer.
        $this->travel(11)->minutes();
        app(BookingService::class)->expireHolds();
        $this->assertSame(1, $svc->match());
        $this->assertSame('passed', $e1->fresh()->status);
        $e2->refresh();
        $this->assertSame('offered', $e2->status);

        // Paid → booked.
        $this->pay($second, $e2->booking);
        $this->assertSame('confirmed', $e2->booking->fresh()->status->value);
        $svc->match();
        $this->assertSame('booked', $e2->fresh()->status);
    }

    public function test_join_rules(): void
    {
        $u = $this->customer();
        // A free slot exists → book it instead.
        $this->join($u)->assertSessionHasErrors('date');
        $this->assertSame(0, WaitlistEntry::count());

        $holder = $this->customer();
        $this->pay($holder, $this->book($holder));
        $this->join($u, ['window_from' => '13:00', 'window_to' => '12:00'])->assertSessionHasErrors('window_to');
        $this->join($u)->assertSessionHasNoErrors();
        $this->join($u)->assertSessionHasErrors('date'); // same station and day
        $this->join($u, ['accept_rules' => '0', 'date' => '2026-11-25'])->assertSessionHasErrors('accept_rules');

        // Leaving closes the entry.
        $e = WaitlistEntry::sole();
        $this->as($this->customer())->post("/account/waitlist/{$e->uuid}/leave")->assertForbidden();
        $this->as($u)->post("/account/waitlist/{$e->uuid}/leave")->assertSessionHas('success');
        $this->assertSame('cancelled', $e->fresh()->status);
    }

    public function test_window_ending_expires_entries_and_admin_can_see_the_queue(): void
    {
        $holder = $this->customer();
        $this->pay($holder, $this->book($holder));
        $u = $this->customer();
        $this->join($u)->assertSessionHasNoErrors();

        $owner = User::where('email', 'owner@greengrid.test')->first();
        $this->as($owner)->get(WaitlistResource::getUrl())->assertOk()->assertSee('Waiter');

        $this->travelTo(Carbon::parse('2026-11-24 12:31', 'Asia/Kolkata'));
        app(WaitlistService::class)->match();
        $this->assertSame('expired', WaitlistEntry::sole()->status);
    }
}

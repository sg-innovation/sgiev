<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\NoShowRecord;
use App\Models\Refund;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Services\NoShowService;
use App\Services\Payments\SimulatedSabPaisaGateway;
use App\Support\BusinessRules;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** C5: reminders, running late, automatic no-shows with refund per policy and a no-show record. */
class NoShowTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        Notification::fake();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->station = Station::where('code', 'GG-PUN-BNR')->first();
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse("2026-11-24 {$time}", 'Asia/Kolkata'));
    }

    private function paid(): Booking
    {
        $charger = $this->station->chargers()->first();
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", [
            'charger_id' => $charger->id, 'start' => Carbon::parse('2026-11-24 12:00', 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
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

    public function test_reminders_are_sent_once_at_each_offset(): void
    {
        $b = $this->paid();
        $svc = app(NoShowService::class);

        $this->at('10:30');
        $this->assertSame(0, $svc->sendReminders());
        $this->at('11:01');
        $this->assertSame(1, $svc->sendReminders());
        $this->assertSame(0, $svc->sendReminders(), 'not twice');
        Notification::assertSentTo($this->customer, BookingNotice::class, fn ($n) => $n->template === 'booking.reminder' && $n->vars['minutes'] === '60');

        // Scheduler missed 11:45 entirely: only the latest due reminder (15) is sent, not 60 again.
        $this->at('11:50');
        $this->assertSame(1, $svc->sendReminders());
        $this->at('12:00');
        $this->assertSame(1, $svc->sendReminders());
        Notification::assertSentTo($this->customer, BookingNotice::class, fn ($n) => $n->template === 'booking.starting');
        $this->assertSame([60, 15, 0], $b->fresh()->reminders_sent);
        Notification::assertSentToTimes($this->customer, BookingNotice::class, 3);
    }

    public function test_auto_no_show_refunds_per_policy_and_frees_the_connector(): void
    {
        $b = $this->paid();
        $svc = app(NoShowService::class);
        $grace = BusinessRules::int('booking.no_show_minutes');

        $this->at('12:0'.max(0, $grace - 1));
        $this->assertSame(0, $svc->sweep());
        $this->at('12:'.str_pad((string) $grace, 2, '0', STR_PAD_LEFT));
        $this->assertSame(1, $svc->sweep());

        $b->refresh();
        $this->assertSame('no_show', $b->status->value);
        $rec = NoShowRecord::where('booking_id', $b->id)->sole();
        $this->assertSame('auto', $rec->method);
        $this->assertNull($rec->marked_by);
        $percent = (int) ($b->cancellation_policy['no_show_refund_percent'] ?? 0);
        $this->assertSame($percent, $rec->refund_percent);
        $this->assertSame((string) $b->total_amount, (string) $rec->paid_amount);
        if ($percent > 0) {
            $this->assertSame((string) $rec->refund_amount, (string) Refund::where('booking_id', $b->id)->value('amount'));
        }
        Notification::assertSentTo($this->customer, BookingNotice::class, fn ($n) => $n->template === 'booking.no_show');
        $this->assertTrue(app(\App\Services\AvailabilityService::class)->isConnectorFree($b->connectorPoint, now()->addMinutes(5), now()->addMinutes(30)), 'connector released');
        $this->assertSame(0, $svc->sweep(), 'idempotent');
    }

    public function test_running_late_extends_the_grace_up_to_the_limit(): void
    {
        $b = $this->paid();
        $grace = BusinessRules::int('booking.no_show_minutes');
        $extra = BusinessRules::int('booking.late_extra_minutes');

        $this->at('10:30');
        $this->actingAs($this->customer)->post("/account/bookings/{$b->code}/running-late", ['minutes' => 10])->assertSessionHasErrors('minutes'); // too early

        $this->at('11:58');
        $this->post("/account/bookings/{$b->code}/running-late", ['minutes' => 10, 'note' => 'Traffic'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('Traffic', $b->late_note);
        $svc = app(NoShowService::class);
        $this->assertSame(min($grace + 8, $grace + $extra), $svc->graceMinutes($b));

        // Not a no-show at the normal grace while the customer is on the way.
        $this->at('12:'.str_pad((string) $grace, 2, '0', STR_PAD_LEFT));
        $this->assertSame(0, $svc->sweep());
        $this->travelTo($svc->noShowAt($b));
        $this->assertSame(1, $svc->sweep());
        $this->assertSame($svc->graceMinutes($b), NoShowRecord::sole()->grace_minutes);

        // A very late ETA is capped.
        $this->assertSame($grace + $extra, $svc->graceMinutes($b->forceFill(['late_eta_at' => $b->start_at->copy()->addHours(3)])));
    }

    public function test_others_cannot_post_running_late_and_auto_can_be_switched_off(): void
    {
        $b = $this->paid();
        $this->at('11:58');
        $other = User::factory()->create();
        $this->actingAs($other)->post("/account/bookings/{$b->code}/running-late", ['minutes' => 10])->assertSessionHasErrors('minutes');

        SystemSetting::query()->updateOrCreate(['key' => 'rule.booking.auto_no_show'], ['value' => '0']);
        BusinessRules::flush();
        $this->at('13:30');
        $this->assertSame(0, app(NoShowService::class)->sweep());
        $this->assertSame('confirmed', $b->fresh()->status->value);
    }
}

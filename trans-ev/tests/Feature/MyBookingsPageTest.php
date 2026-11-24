<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** My bookings: tabs, counts, search and per-booking actions. */
class MyBookingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_counts_and_search(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class]);
        $me = User::factory()->create();
        $me->assignRole(Role::Customer->value);
        $station = Station::where('code', 'PAT-BLY')->first();

        $upcoming = Booking::factory()->forStation($station, $me)->create(['status' => BookingStatus::Confirmed]);
        $past = Booking::factory()->forStation($station, $me)->create([
            'status' => BookingStatus::Completed, 'start_at' => now()->subDays(3), 'end_at' => now()->subDays(3)->addHour(), 'blocked_until' => now()->subDays(3)->addHour(),
        ]);
        Booking::factory()->forStation($station, $me)->create([
            'status' => BookingStatus::Cancelled, 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour(), 'blocked_until' => now()->addDays(2)->addHour(),
        ]);
        Booking::factory()->forStation($station, User::factory()->create())->create(['status' => BookingStatus::Confirmed, 'start_at' => now()->addDays(3), 'end_at' => now()->addDays(3)->addHour(), 'blocked_until' => now()->addDays(3)->addHour()]);

        $this->actingAs($me)->get('/account/bookings')->assertOk()->assertInertia(fn ($p) => $p->component('booking/index')
            ->where('tab', 'upcoming')
            ->where('counts', ['upcoming' => 1, 'past' => 1, 'cancelled' => 1, 'all' => 3])
            ->has('bookings.data', 1)
            ->where('bookings.data.0.code', $upcoming->code)
            ->has('bookings.data.0.lat')
            ->where('stats.sessions', 2));

        $this->get('/account/bookings?tab=past')->assertInertia(fn ($p) => $p->where('bookings.data.0.code', $past->code));
        $this->get('/account/bookings?tab=all&q='.substr($past->code, -5))->assertInertia(fn ($p) => $p->has('bookings.data', 1));
        $this->get('/account/bookings?tab=all&q=nothing-matches')->assertInertia(fn ($p) => $p->has('bookings.data', 0));

        // "Get help" pre-selects the booking on the support form.
        $this->get('/account/support?booking='.$upcoming->code)->assertInertia(fn ($p) => $p->where('preselectBooking', $upcoming->id));
        Carbon::setTestNow();
    }
}

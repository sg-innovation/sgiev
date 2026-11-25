<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Review;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Services\ReviewService;
use App\Support\BusinessRules;
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

/** D3: ratings & reviews — eligibility, moderation, owner reply, averages, station page, rating requests. */
class ReviewTest extends TestCase
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
        $this->customer = User::factory()->create(['name' => 'Asha Rao']);
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

    private function booking(string $status = 'completed', ?User $user = null): Booking
    {
        $charger = $this->station->chargers()->first();

        return Booking::query()->create([
            'company_id' => $this->station->company_id, 'user_id' => ($user ?? $this->customer)->id, 'station_id' => $this->station->id, 'charger_id' => $charger->id,
            'connector_id' => $charger->connectorPoints()->first()->id, 'tariff_id' => $this->station->tariffs()->first()->id, 'connector' => $charger->connectorPoints()->first()->type->value,
            'start_at' => now()->subHours(3), 'end_at' => now()->subHours(2), 'blocked_until' => now()->subHours(2), 'status' => $status,
            'battery_from' => 20, 'battery_to' => 80, 'est_kwh' => 10, 'rate_per_kwh' => 15, 'energy_amount' => 150, 'session_fee' => 0, 'taxable_amount' => 150,
            'gst_rate' => 18, 'total_amount' => 177, 'sac_code' => '998714', 'place_of_supply' => '27', 'supplier_state_code' => '27',
            'customer_snapshot' => ['name' => 'Asha Rao'], 'vehicle_snapshot' => ['registration_no' => 'MH12AB1234'], 'rule_set_id' => RuleSet::activeFor($this->station)->id,
        ]);
    }

    public function test_star_rating_publishes_at_once_and_updates_averages(): void
    {
        $b = $this->booking();
        $this->as($this->customer)->get("/account/bookings/{$b->code}")->assertInertia(fn ($p) => $p->where('booking.review.existing', null)->where('booking.review.canEdit', true));
        $this->post("/account/bookings/{$b->code}/review", ['station_rating' => 4, 'charger_rating' => 5, 'tags' => ['fast_charging', 'not_a_real_tag']])->assertSessionHas('success');

        $r = Review::sole();
        $this->assertSame('published', $r->status);
        $this->assertSame(['fast_charging'], $r->tags);
        $this->assertSame('4.00', (string) $this->station->fresh()->rating_avg);
        $this->assertSame(1, $this->station->fresh()->rating_count);
        $this->assertSame('5.00', (string) $b->charger->fresh()->rating_avg);

        // A second booking rated 2 → average 3.0; editing the first to 5 → 3.5.
        $this->post('/account/bookings/'.$this->booking()->code.'/review', ['station_rating' => 2])->assertSessionHas('success');
        $this->assertSame('3.00', (string) $this->station->fresh()->rating_avg);
        $this->post("/account/bookings/{$b->code}/review", ['station_rating' => 5])->assertSessionHas('success');
        $this->assertSame(2, Review::count());
        $this->assertSame('3.50', (string) $this->station->fresh()->rating_avg);

        // Shown on the station page and in station cards.
        $this->get("/stations/{$this->station->slug}")->assertInertia(fn ($p) => $p->where('reviews.count', 2)->where('reviews.avg', 3.5)->where('reviews.items.0.name', 'Asha R.')
            ->where('station.rating.avg', 3.5));
    }

    public function test_written_reviews_are_moderated_and_owner_can_reply(): void
    {
        $b = $this->booking();
        $this->as($this->customer)->post("/account/bookings/{$b->code}/review", ['station_rating' => 2, 'body' => 'Charger stopped twice. <script>x</script>'])->assertSessionHas('success');
        $r = Review::sole();
        $this->assertSame('pending', $r->status);
        $this->assertStringNotContainsString('<script>', $r->body);
        $this->assertSame(0, $this->station->fresh()->rating_count, 'not counted until approved');
        $this->get("/stations/{$this->station->slug}")->assertInertia(fn ($p) => $p->where('reviews.count', 0));

        // Operator can see and reply, but not approve.
        $this->as($this->owner)->get(\App\Filament\Resources\Reviews\ReviewResource::getUrl())->assertOk()->assertSee('Charger stopped twice');
        Livewire::test(ListReviews::class)->assertTableActionHidden('approve', $r)->assertTableActionVisible('reply', $r)
            ->callTableAction('reply', $r, ['reply' => 'Sorry! We have serviced the charger.']);
        $this->assertSame('Sorry! We have serviced the charger.', $r->fresh()->owner_reply);

        // Trans EV publishes it; the customer is told about the reply once it is public.
        $admin = User::factory()->create(['company_id' => Company::where('is_platform', true)->value('id')]);
        $admin->assignRole(Role::SuperAdmin->value);
        $this->as($admin);
        Livewire::test(ListReviews::class)->callTableAction('approve', $r);
        $this->assertSame('published', $r->fresh()->status);
        $this->assertSame(1, $this->station->fresh()->rating_count);
        app(ReviewService::class)->reply($r->fresh(), 'Sorry! We have serviced the charger.', $this->owner);
        Notification::assertSentTo($this->customer, BookingNotice::class, fn ($n) => $n->template === 'review.reply');
        $this->get("/stations/{$this->station->slug}")->assertInertia(fn ($p) => $p->where('reviews.items.0.reply.text', 'Sorry! We have serviced the charger.'));

        // Hiding removes it from averages.
        Livewire::test(ListReviews::class)->callTableAction('hide', $r, ['note' => 'Contains personal details']);
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertSame(0, $this->station->fresh()->rating_count);
    }

    public function test_eligibility_rules(): void
    {
        $this->as($this->customer)->post('/account/bookings/'.$this->booking('confirmed')->code.'/review', ['station_rating' => 5])->assertSessionHasErrors('station_rating');
        $other = User::factory()->create();
        $mine = $this->booking();
        $this->as($other)->post("/account/bookings/{$mine->code}/review", ['station_rating' => 5])->assertSessionHasErrors('station_rating');
        $this->travel(BusinessRules::int('reviews.window_days') + 1)->days();
        $this->as($this->customer)->post("/account/bookings/{$mine->code}/review", ['station_rating' => 5])->assertSessionHasErrors('station_rating');
        $this->post("/account/bookings/{$mine->code}/review", ['station_rating' => 9])->assertSessionHasErrors('station_rating');
        $this->assertSame(0, Review::count());
    }

    public function test_rating_request_is_sent_once_after_charging(): void
    {
        $b = $this->booking();
        $b->forceFill(['end_at' => now()->subMinutes(30)])->save();
        $svc = app(ReviewService::class);
        $this->assertSame(0, $svc->sendRequests(), 'too soon');
        $this->travel(31)->minutes();
        $this->assertSame(1, $svc->sendRequests());
        $this->assertSame(0, $svc->sendRequests());
        Notification::assertSentTo($this->customer, BookingNotice::class, fn ($n) => $n->template === 'review.request');

        // Not sent when already reviewed; no moderation when switched off.
        SystemSetting::query()->updateOrCreate(['key' => 'rule.reviews.moderate_text'], ['value' => '0']);
        BusinessRules::flush();
        $this->as($this->customer)->post("/account/bookings/{$b->code}/review", ['station_rating' => 5, 'body' => 'Great!'])->assertSessionHas('success');
        $this->assertSame('published', Review::sole()->status);
    }
}

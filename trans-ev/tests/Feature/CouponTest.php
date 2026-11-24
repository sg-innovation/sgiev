<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleSetSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Coupons & discounts: validation rules, GST on the discounted value, usage limits, admin scoping. */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Kolkata'));
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, StationSeeder::class, RuleSetSeeder::class, DemoCompanySeeder::class]);
        $this->customer = User::factory()->create(['phone' => '9876543210']);
        $this->customer->assignRole(Role::Customer->value);
        $this->station = Station::where('code', 'PAT-BLY')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = [], string $time = '12:00'): array
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();

        return array_merge([
            'charger_id' => $charger->id, 'start' => Carbon::parse("2026-11-25 {$time}", 'Asia/Kolkata')->toIso8601String(), 'duration' => 60,
            'make' => 'Tata', 'model' => 'Nexon EV', 'registration_no' => 'BR01AB1234', 'battery_kwh' => 40, 'connector' => 'ccs2',
            'battery_from' => 20, 'battery_to' => 80, 'save_vehicle' => false,
            'name' => 'Test Driver', 'email' => 'driver@example.com', 'phone' => '9876543210', 'gstin' => '', 'state_code' => '10',
            'rule_set_id' => RuleSet::activeFor($this->station)->id, 'accept_rules' => true,
        ], $overrides);
    }

    private function estimate(?string $code): array
    {
        $charger = $this->station->chargers()->where('type', 'DC')->first();

        return $this->actingAs($this->customer)->postJson("/book/{$this->station->slug}/estimate", [
            'charger_id' => $charger->id, 'battery_kwh' => 40, 'battery_from' => 20, 'battery_to' => 80, 'duration' => 60, 'coupon_code' => $code,
        ])->assertOk()->json();
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge(['code' => 'SAVE20', 'name' => 'Save 20', 'type' => 'percent', 'value' => '20', 'is_active' => true, 'per_user_limit' => null], $attrs));
    }

    public function test_percent_coupon_reduces_taxable_value_and_gst(): void
    {
        $this->coupon(['max_discount' => '1000']);
        $full = $this->estimate(null);
        $e = $this->estimate('save20');

        $this->assertNull($e['coupon_error']);
        $this->assertSame('SAVE20', $e['coupon_code']);
        $expected = round($full['subtotal_amount'] * 0.20, 2);
        $this->assertEqualsWithDelta($expected, $e['discount_amount'], 0.01);
        $this->assertEqualsWithDelta($full['subtotal_amount'] - $expected, $e['taxable_amount'], 0.01);
        // GST is charged on the discounted value (intra-state Bihar: CGST + SGST 9% each).
        $this->assertEqualsWithDelta(round($e['taxable_amount'] * 0.09, 2), $e['cgst_amount'], 0.01);
        $this->assertLessThan($full['total_amount'], $e['total_amount']);
    }

    public function test_percent_cap_and_flat_never_exceed_the_subtotal(): void
    {
        $this->coupon(['code' => 'CAP', 'value' => '50', 'max_discount' => '25']);
        $this->assertEqualsWithDelta(25.0, $this->estimate('CAP')['discount_amount'], 0.001);

        $this->coupon(['code' => 'HUGE', 'type' => 'flat', 'value' => '99999']);
        $e = $this->estimate('HUGE');
        $this->assertEqualsWithDelta($e['subtotal_amount'] - 1, $e['discount_amount'], 0.001, 'at least ₹1 stays payable');
        $this->assertGreaterThan(0, $e['total_amount']);
    }

    public function test_invalid_coupons_return_a_message_and_full_price(): void
    {
        $this->coupon(['code' => 'OLD', 'ends_at' => now()->subDay()]);
        $this->coupon(['code' => 'ACONLY', 'charger_type' => 'AC']);
        $this->coupon(['code' => 'BIG', 'min_order' => '100000']);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->coupon(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        $gg = Company::where('slug', 'greengrid-demo')->first();
        $this->coupon(['code' => 'GGONLY', 'company_id' => $gg->id]);

        $full = $this->estimate(null)['total_amount'];
        foreach (['NOPE' => 'not valid', 'OLD' => 'expired', 'ACONLY' => 'AC', 'BIG' => 'minimum', 'OFF' => 'not valid', 'SOON' => 'not active yet', 'GGONLY' => 'not valid at this station'] as $code => $msg) {
            $e = $this->estimate($code);
            $this->assertStringContainsString($msg, (string) $e['coupon_error'], $code);
            $this->assertEquals($full, $e['total_amount'], $code);
            $this->assertEquals(0, $e['discount_amount'], $code);
        }
    }

    public function test_booking_stores_the_discount_and_per_user_limit_is_enforced(): void
    {
        $coupon = $this->coupon(['per_user_limit' => 1]);
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload(['coupon_code' => 'SAVE20']))->assertRedirect();

        $b = Booking::latest('id')->first();
        $this->assertSame($coupon->id, $b->coupon_id);
        $this->assertSame('SAVE20', $b->coupon_code);
        $this->assertSame('platform', $b->discount_funded_by);
        $this->assertGreaterThan(0, (float) $b->discount_amount);
        $this->assertSame(bcsub((string) $b->subtotal_amount, (string) $b->discount_amount, 2), (string) $b->taxable_amount);

        $this->post("/book/{$this->station->slug}", $this->payload(['coupon_code' => 'SAVE20'], '14:00'))->assertSessionHasErrors('coupon_code');
    }

    public function test_cancelled_or_expired_bookings_free_the_coupon(): void
    {
        $this->coupon(['per_user_limit' => 1, 'usage_limit' => 1]);
        $this->actingAs($this->customer)->post("/book/{$this->station->slug}", $this->payload(['coupon_code' => 'SAVE20']))->assertRedirect();
        Booking::latest('id')->first()->forceFill(['status' => BookingStatus::Expired])->save();

        $this->post("/book/{$this->station->slug}", $this->payload(['coupon_code' => 'SAVE20'], '14:00'))->assertSessionHasNoErrors();
    }

    public function test_first_booking_only(): void
    {
        $this->coupon(['code' => 'FIRST', 'first_booking_only' => true]);
        Booking::factory()->forStation($this->station, $this->customer)->create(['status' => BookingStatus::Completed]);

        $this->assertStringContainsString('first booking', (string) $this->estimate('FIRST')['coupon_error']);
    }

    public function test_operator_coupon_needs_the_plan_feature(): void
    {
        $gg = Company::where('slug', 'greengrid-demo')->first();
        $ggStation = Station::where('code', 'GG-PUN-BNR')->first();
        $this->coupon(['code' => 'GG10', 'company_id' => $gg->id, 'value' => '10']);
        $charger = $ggStation->chargers()->first();
        $body = ['charger_id' => $charger->id, 'battery_kwh' => 40, 'battery_from' => 20, 'battery_to' => 80, 'duration' => 60, 'coupon_code' => 'GG10'];

        // Free plan: coupons are a premium feature.
        $this->actingAs($this->customer)->postJson("/book/{$ggStation->slug}/estimate", $body)->assertJsonPath('discount_amount', 0);

        $gg->forceFill(['plan_id' => Plan::where('slug', 'premium')->value('id')])->save();
        $this->assertGreaterThan(0, $this->postJson("/book/{$ggStation->slug}/estimate", $body)->json('discount_amount'));
    }

    public function test_public_offers_are_shown_on_the_wizard(): void
    {
        $this->coupon(['code' => 'PUBLIC', 'is_public' => true]);
        $this->coupon(['code' => 'SECRET', 'is_public' => false]);

        $this->actingAs($this->customer)->get("/book/{$this->station->slug}")->assertOk()
            ->assertInertia(fn ($p) => $p->has('offers', 1)->where('offers.0.code', 'PUBLIC'));
    }

    public function test_admin_scoping_and_company_coupons(): void
    {
        $gg = Company::where('slug', 'greengrid-demo')->first();
        $platformCoupon = $this->coupon(['code' => 'PLAT']);
        $ggCoupon = $this->coupon(['code' => 'GGOWN', 'company_id' => $gg->id]);
        $owner = User::where('email', 'owner@greengrid.test')->first();

        // Free plan: no coupons screen.
        $this->actingAs($owner)->get(CouponResource::getUrl('index'))->assertForbidden();

        $gg->forceFill(['plan_id' => Plan::where('slug', 'premium')->value('id')])->save();
        $this->actingAs($owner->fresh());
        Livewire::test(ListCoupons::class)->assertCanSeeTableRecords([$ggCoupon])->assertCanNotSeeTableRecords([$platformCoupon]);

        // A company can't create a coupon for another company or the platform.
        $platformStation = Station::where('code', 'DEL-CP')->first();
        Livewire::test(CreateCoupon::class)
            ->fillForm(['code' => 'mine', 'name' => 'Mine', 'type' => 'flat', 'value' => 30, 'station_ids' => [$platformStation->id], 'is_active' => true])
            ->call('create')->assertHasFormErrors(['station_ids.0']);
        Livewire::test(CreateCoupon::class)
            ->fillForm(['code' => 'mine', 'name' => 'Mine', 'type' => 'flat', 'value' => 30, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame($gg->id, Coupon::where('code', 'MINE')->value('company_id'), 'company coupons are always funded by the company');
        // Defence in depth: the server-side clean-up also drops foreign stations.
        $this->assertNull(CouponResource::sanitise(['company_id' => $gg->id, 'type' => 'flat', 'station_ids' => [$platformStation->id]])['station_ids']);
    }
}

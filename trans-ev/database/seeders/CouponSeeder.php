<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

/** Demo offers (platform-funded). Edit or deactivate them in Admin → Coupons & offers. */
class CouponSeeder extends Seeder
{
    public function run(): void
    {
        Coupon::query()->updateOrCreate(['code' => 'WELCOME50'], [
            'name' => 'Welcome offer', 'description' => '₹50 off your first charge on Trans EV.',
            'type' => 'flat', 'value' => '50.00', 'min_order' => '150.00', 'per_user_limit' => 1,
            'first_booking_only' => true, 'is_public' => true, 'is_active' => true,
        ]);
        Coupon::query()->updateOrCreate(['code' => 'DCFAST20'], [
            'name' => 'DC fast charging offer', 'description' => '20% off DC fast charging.',
            'type' => 'percent', 'value' => '20.00', 'max_discount' => '100.00', 'charger_type' => 'DC',
            'per_user_limit' => 3, 'usage_limit' => 500, 'ends_at' => now()->addMonths(3),
            'is_public' => true, 'is_active' => true,
        ]);
    }
}

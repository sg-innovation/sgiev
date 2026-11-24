<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Subscription plans for partner companies (SRS §Plans). Free is the default for new partners. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free',
            'description' => 'Everything a small operator needs to list stations and take online bookings.',
            'price_monthly' => '0.00',
            'commission_percent' => '5.00', // starting rate; set your own in Admin → Plans & features
            'features' => Plan::FREE_FEATURES,
            'max_stations' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort' => 1,
        ]);

        Plan::query()->firstOrCreate(['slug' => 'premium'], [
            'name' => 'Premium',
            'description' => 'Unlimited stations with advanced analytics, coupons, fleet tools and priority support.',
            'price_monthly' => '4999.00',
            'commission_percent' => '3.00',
            'features' => array_keys(Plan::FEATURES),
            'max_stations' => null,
            'is_default' => false,
            'is_active' => true,
            'sort' => 2,
        ]);
    }
}

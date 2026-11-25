<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Final close-out: modules built after the plans were created become plan features. Every plan gets maintenance,
 * waiting list and reviews (free features); plans that already include the premium features also get OCPP.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            $add = ['maintenance', 'waitlist', 'reviews'];
            if (in_array('advanced_reports', $features, true)) {
                $add[] = 'ocpp';
            }
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode(array_values(array_unique(array_merge($features, $add))))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = array_values(array_diff(json_decode((string) $plan->features, true) ?: [], ['maintenance', 'waitlist', 'reviews', 'ocpp']));
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        }
    }
};

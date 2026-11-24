<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing v2 (SGI EV B4): time-of-day energy rates (peak / off-peak bands) and parking charges on each tariff,
 * and the itemised price lines saved on each booking (shown on the receipt and invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->json('time_bands')->nullable()->after('rate_per_kwh');   // [{name, days:[0-6], from:"18:00", to:"22:00", rate_per_kwh}]
            $table->decimal('parking_fee', 8, 2)->default(0)->after('session_fee');            // per booking
            $table->decimal('parking_fee_per_hour', 8, 2)->default(0)->after('parking_fee');   // per hour of the booked slot
            $table->string('parking_sac', 8)->default('996743')->after('sac_code');            // parking services
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('parking_amount', 12, 2)->default(0)->after('session_fee');
            $table->json('price_lines')->nullable()->after('parking_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['parking_amount', 'price_lines']));
        Schema::table('tariffs', fn (Blueprint $table) => $table->dropColumn(['time_bands', 'parking_fee', 'parking_fee_per_hour', 'parking_sac']));
    }
};

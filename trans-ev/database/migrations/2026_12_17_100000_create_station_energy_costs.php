<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1: what each station pays for electricity per month (from the DISCOM bill), used for the gross-profit estimate.
 * Cost = energy delivered in the month × cost per kWh + fixed / demand charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_energy_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->date('month')->comment('First day of the month');
            $table->decimal('cost_per_kwh', 8, 4);
            $table->decimal('fixed_charges', 12, 2)->default(0)->comment('Fixed / demand charges for the month');
            $table->decimal('units_billed', 12, 3)->nullable()->comment('kWh on the DISCOM bill, for reference');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['station_id', 'month']);
            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_energy_costs');
    }
};

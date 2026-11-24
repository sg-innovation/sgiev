<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price history is kept: a change closes the old row (effective_to) and adds a new one.
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charger_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('charger_type', 2)->nullable(); // applies to all AC or all DC chargers when charger_id is null
            $table->string('name');
            $table->decimal('rate_per_kwh', 8, 2);
            $table->decimal('session_fee', 8, 2)->default(0);
            $table->decimal('idle_fee_per_min', 8, 2)->default(0);
            $table->unsignedSmallInteger('idle_grace_minutes')->default(10);
            $table->decimal('gst_rate', 5, 2)->default(18.00);
            $table->string('sac_code', 8)->default('998714');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['station_id', 'effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};

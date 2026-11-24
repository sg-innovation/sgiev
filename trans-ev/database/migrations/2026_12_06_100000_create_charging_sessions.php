<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C2: charging sessions started and stopped by staff (manual mode now; OCPP fills the same table later). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('charger_id')->constrained()->restrictOnDelete();
            $table->foreignId('connector_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('source', 10)->default('manual'); // manual, ocpp
            $table->string('status', 12)->default('active')->index(); // active, completed
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->decimal('meter_start_kwh', 12, 3)->nullable();
            $table->decimal('meter_stop_kwh', 12, 3)->nullable();
            $table->decimal('energy_kwh', 10, 3)->nullable();
            $table->string('energy_source', 12)->nullable(); // meter, entered
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stop_reason', 40)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['connector_id', 'status']);
            $table->index(['station_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_sessions');
    }
};

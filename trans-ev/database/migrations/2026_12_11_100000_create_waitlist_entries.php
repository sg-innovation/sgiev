<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C7 waiting list. A customer queues for a station, day, time window, length and vehicle. When a fitting slot frees
 * up, the first in line is offered it as a held booking with a short time to pay; if they don't, it passes on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');                       // station-local day
            $table->time('window_from');                // earliest start (local)
            $table->time('window_to');                  // latest start (local)
            $table->unsignedSmallInteger('duration');   // minutes
            $table->string('connector', 20);            // vehicle connector type
            $table->json('vehicle');                    // make, model, registration_no, battery_kwh, connector
            $table->unsignedTinyInteger('battery_from');
            $table->unsignedTinyInteger('battery_to');
            $table->json('customer');                   // name, email, phone, gstin, state_code
            $table->foreignId('rule_set_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('waiting')->index(); // waiting, offered, booked, passed, expired, cancelled
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->unsignedTinyInteger('offers_made')->default(0);
            $table->string('closed_reason', 160)->nullable();
            $table->timestamps();
            $table->index(['station_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('make', 60);
            $table->string('model', 80);
            $table->string('registration_no', 15);
            $table->decimal('battery_kwh', 6, 2);
            $table->string('connector', 20);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'registration_no']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 20)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('charger_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tariff_id')->constrained()->restrictOnDelete();
            $table->string('connector', 20);

            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->timestamp('blocked_until'); // end_at + station buffer, used for overlap checks
            $table->string('status', 20)->index();
            $table->timestamp('hold_expires_at')->nullable();

            $table->unsignedTinyInteger('battery_from');
            $table->unsignedTinyInteger('battery_to');

            // Estimate (all money DECIMAL, computed with bcmath). Final amounts come in Phase 3/4.
            $table->decimal('est_kwh', 10, 3);
            $table->decimal('rate_per_kwh', 8, 2);
            $table->decimal('energy_amount', 12, 2);
            $table->decimal('session_fee', 12, 2);
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('gst_rate', 5, 2);
            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('sac_code', 8);
            $table->string('place_of_supply', 2);
            $table->string('supplier_state_code', 2);

            $table->json('customer_snapshot'); // name, email, phone, gstin, state_code at time of booking
            $table->json('vehicle_snapshot');
            $table->foreignId('rule_set_id')->constrained()->restrictOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['charger_id', 'status', 'start_at', 'blocked_until']);
            $table->index(['user_id', 'status']);
            $table->index(['station_id', 'start_at']);
        });

        // Append-only proof that the customer accepted a specific version of the station rules.
        Schema::create('rule_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('rule_set_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->char('content_hash', 64); // SHA-256 of the exact text accepted
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_acceptances');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('vehicles');
    }
};

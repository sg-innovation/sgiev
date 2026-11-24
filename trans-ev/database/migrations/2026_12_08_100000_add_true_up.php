<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C4: meter readings per session and the final bill / true-up of prepaid bookings against the energy delivered.
 * Less energy → the difference is refunded (credit note). More energy → absorbed by the operator, or collected
 * as a balance with a GST debit note when the business rule allows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_meter_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charging_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('measured_at');
            $table->decimal('register_kwh', 12, 3)->nullable(); // meter register reading
            $table->decimal('energy_kwh', 10, 3)->nullable();   // delivered so far in this session
            $table->decimal('power_kw', 8, 2)->nullable();
            $table->unsignedTinyInteger('soc')->nullable();      // vehicle state of charge %, when known
            $table->string('context', 12);                       // start, sample, stop
            $table->string('source', 10)->default('manual');     // manual, ocpp
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['charging_session_id', 'measured_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('final_kwh', 10, 3)->nullable()->after('est_kwh');
            $table->decimal('final_total', 12, 2)->nullable()->after('total_amount');
            $table->decimal('trueup_amount', 12, 2)->nullable()->after('final_total'); // final − prepaid (negative = refund)
            $table->string('trueup_status', 16)->nullable()->after('trueup_amount');   // none, refunded, refund_failed, absorbed, due, paid
            $table->decimal('balance_due', 12, 2)->nullable()->after('trueup_status');
            $table->json('final_lines')->nullable()->after('price_lines');
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('note_type', 6)->default('credit')->after('uuid'); // credit, debit
            $table->foreignId('payment_id')->nullable()->after('refund_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn('note_type');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['final_kwh', 'final_total', 'trueup_amount', 'trueup_status', 'balance_due', 'final_lines']);
        });
        Schema::dropIfExists('charging_meter_values');
    }
};

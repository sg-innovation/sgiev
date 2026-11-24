<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C6: every change of a booking's time or charger, by the customer (within policy) or the operator. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->timestamp('from_start');
            $table->timestamp('from_end');
            $table->foreignId('from_charger_id')->constrained('chargers')->restrictOnDelete();
            $table->foreignId('from_connector_id')->nullable()->constrained('connectors')->nullOnDelete();
            $table->timestamp('to_start');
            $table->timestamp('to_end');
            $table->foreignId('to_charger_id')->constrained('chargers')->restrictOnDelete();
            $table->foreignId('to_connector_id')->nullable()->constrained('connectors')->nullOnDelete();
            $table->string('by_role', 10); // customer, operator
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reschedules');
    }
};

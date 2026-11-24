<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** B8 reconciliation: daily cross-check of gateway, payments, bookings, refunds, GST documents and wallets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20)->default('schedule'); // schedule, manual
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->unsignedInteger('new_exceptions')->default(0);
            $table->unsignedInteger('cleared_exceptions')->default(0);
            $table->unsignedInteger('open_exceptions')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 160)->unique();
            $table->string('type', 40)->index();
            $table->string('severity', 16)->index();
            $table->string('status', 16)->default('open')->index(); // open, resolved, ignored
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary');
            $table->decimal('expected_amount', 12, 2)->nullable();
            $table->decimal('actual_amount', 12, 2)->nullable();
            $table->json('details')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->foreignId('first_run_id')->nullable()->constrained('reconciliation_runs')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->constrained('reconciliation_runs')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('reconciliation_runs');
    }
};

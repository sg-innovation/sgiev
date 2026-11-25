<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the review gaps and adds G3:
 *  - customer profile photo and city
 *  - masked card / UPI ID on payments (shown on receipts and invoices)
 *  - charger maintenance work orders
 *  - franchises (regional partners over several stations), regional managers and franchise commission payouts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 255)->nullable()->after('phone');
            $table->string('city', 80)->nullable()->after('avatar_path');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_instrument', 60)->nullable()->after('payment_mode')->comment('Masked: VISA •••• 4242 or ra•••@okicici');
        });

        Schema::create('charger_work_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->comment('fault | repair | preventive | inspection | calibration | other');
            $table->string('priority', 10)->default('normal')->comment('low | normal | high | urgent');
            $table->string('status', 15)->default('open')->comment('open | in_progress | on_hold | done | cancelled');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->boolean('took_offline')->default(false)->comment('Charger set to maintenance while the work order is open');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor', 120)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('downtime_minutes')->nullable();
            $table->decimal('labour_cost', 12, 2)->nullable();
            $table->decimal('parts_cost', 12, 2)->nullable();
            $table->text('parts_used')->nullable();
            $table->text('resolution')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['charger_id', 'opened_at']);
        });

        Schema::create('franchises', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('legal_name', 160)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('contact_name', 120);
            $table->string('email', 255);
            $table->string('phone', 15)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 60)->nullable();
            $table->string('region', 120)->nullable()->comment('Territory, e.g. "Pune & PCMC"');
            $table->string('status', 12)->default('pending')->comment('pending | active | suspended | rejected');
            $table->decimal('commission_percent', 5, 2)->default(0)->comment('Share of net sales (excl. GST) of its stations');
            $table->text('application_note')->nullable();
            $table->text('review_note')->nullable();
            $table->json('bank_details')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('franchise_station', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->unique()->constrained()->cascadeOnDelete()->comment('A station belongs to at most one franchise');
            $table->date('joined_on')->nullable();
            $table->timestamps();
        });

        Schema::create('franchise_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['franchise_id', 'user_id']);
        });

        Schema::create('franchise_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 10)->default('draft')->comment('draft | approved | paid | void');
            $table->unsignedInteger('stations_count')->default(0);
            $table->unsignedInteger('sessions_count')->default(0);
            $table->decimal('energy_kwh', 14, 3)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 14, 2)->default(0);
            $table->json('lines')->nullable()->comment('Per-station breakdown');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['franchise_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_payouts');
        Schema::dropIfExists('franchise_user');
        Schema::dropIfExists('franchise_station');
        Schema::dropIfExists('franchises');
        Schema::dropIfExists('charger_work_orders');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('payment_instrument'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['avatar_path', 'city']));
    }
};

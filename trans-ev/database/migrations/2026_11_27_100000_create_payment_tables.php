<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment attempt (a booking may have several attempts; at most one succeeds).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();   // the operator whose SabPaisa account is paid
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 20)->default('sabpaisa');
            $table->string('environment', 12);                                      // staging / production / simulator
            $table->string('merchant_txn_id', 100)->unique();                       // our id sent to the gateway
            $table->string('gateway_payment_id', 100)->nullable()->index();         // SabPaisa paymentId (session)
            $table->string('gateway_txn_id', 100)->nullable()->index();             // SabPaisa transaction id (used for refunds)
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->index();                                  // created, pending, success, failed
            $table->string('gateway_status', 30)->nullable();                       // raw gateway status (SUCCESS, FAILED, EXPIRED…)
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_mode', 30)->nullable();
            $table->string('bank_txn_id', 100)->nullable();
            $table->string('bank_rrn', 100)->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('client_secret')->nullable();                              // encrypted, needed only for the redirect
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('verified_via', 20)->nullable();                         // enquiry / webhook / simulator
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('enquiry_attempts')->default(0);
            $table->timestamp('last_enquiry_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // Append-only log of everything received from or sent to the gateway (audit + webhook de-duplication).
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20);           // create, return, webhook, enquiry, refund, simulator
            $table->string('event', 60)->nullable();
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->boolean('signature_valid')->nullable();
            $table->json('payload')->nullable();     // secrets removed
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('fee_retained', 12, 2)->default(0);   // cancellation charge kept, per the policy shown at booking
            $table->string('reason', 500);
            $table->string('policy_rule', 60)->nullable();        // e.g. "full (>2h)", "50% (30m–2h)", "operator cancelled"
            $table->string('status', 20)->index();                // initiated, success, failed
            $table->string('gateway_refund_id', 100)->nullable()->index();
            $table->string('bank_refund_id', 100)->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = 'payment_events_no_'.strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$op} ON `payment_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_events is append-only'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `payment_events_no_update`');
            DB::unprepared('DROP TRIGGER IF EXISTS `payment_events_no_delete`');
        }
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};

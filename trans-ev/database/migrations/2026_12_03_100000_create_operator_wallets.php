<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid operator wallets (B7b). In "wallet" billing mode an operator keeps a prepaid balance with Trans EV;
 * a booking can only be made when the balance covers that booking's platform fee, which is debited when the
 * customer pays (and credited back pro rata on refunds). The ledger is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('billing_mode', 10)->default('invoice')->after('fee_terms_agreed_at'); // wallet | invoice
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('low_balance_alert', 12, 2)->default(500);
            $table->timestamp('low_balance_alerted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);           // recharge | manual_credit | manual_debit | platform_fee | fee_reversal | coupon_credit | subscription
            $table->decimal('amount', 12, 2);     // + credit / − debit
            $table->decimal('balance_after', 14, 2);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('statement_id')->nullable()->constrained('platform_fee_statements')->nullOnDelete();
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'id']);
            $table->index(['booking_id', 'type']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('platform_fee', 12, 2)->nullable()->after('discount_funded_by'); // incl. GST, wallet-mode operators only
        });

        // A payment can be for a booking or for a wallet recharge (paid to Trans EV).
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purpose', 10)->default('booking')->after('booking_id');
            $table->foreignId('payer_company_id')->nullable()->after('purpose')->constrained('companies')->nullOnDelete();
        });
        DB::statement('ALTER TABLE payments MODIFY booking_id BIGINT UNSIGNED NULL');

        if (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = 'wallet_transactions_no_'.strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$op} ON `wallet_transactions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transactions is append-only'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `wallet_transactions_no_update`');
            DB::unprepared('DROP TRIGGER IF EXISTS `wallet_transactions_no_delete`');
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payer_company_id');
            $table->dropColumn('purpose');
        });
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('platform_fee'));
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('billing_mode'));
    }
};

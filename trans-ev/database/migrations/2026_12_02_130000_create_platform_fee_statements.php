<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform fees billed to operators (SGI EV B7). Customers pay operators directly (each operator's own SabPaisa
 * account), so Trans EV invoices each operator monthly for commission, per-booking fees and the plan subscription,
 * and credits back discounts it funded through platform coupons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->default(0)->after('price_monthly');   // on net charging sales (taxable value)
            $table->decimal('per_booking_fee', 8, 2)->default(0)->after('commission_percent');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->nullable()->after('plan_id');   // overrides the plan when set
            $table->decimal('per_booking_fee', 8, 2)->nullable()->after('commission_percent');
        });

        Schema::create('platform_fee_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 10)->default('draft');            // draft | issued | paid | void
            $table->unsignedInteger('bookings_count')->default(0);
            $table->decimal('sales_taxable', 14, 2)->default(0);        // operator's net charging sales (excl. GST)
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('per_booking_fee', 8, 2)->default(0);
            $table->decimal('per_booking_amount', 12, 2)->default(0);
            $table->decimal('subscription_amount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->decimal('cgst', 12, 2)->default(0);
            $table->decimal('sgst', 12, 2)->default(0);
            $table->decimal('igst', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);               // fee invoice total
            $table->decimal('reimbursement_amount', 12, 2)->default(0); // platform-funded discounts owed to the operator
            $table->decimal('net_payable', 12, 2)->default(0);          // total − reimbursement (negative = Trans EV pays)
            $table->json('lines');
            $table->json('supplier')->nullable();
            $table->json('recipient')->nullable();
            $table->string('supplier_gstin', 15)->nullable();
            $table->string('recipient_gstin', 15)->nullable();
            $table->string('place_of_supply', 2)->nullable();
            $table->string('sac_code', 8)->nullable();
            $table->string('number', 16)->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('financial_year', 7)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'period_start']);
            $table->index(['status', 'due_on']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `platform_fee_statements_no_delete`');
            DB::unprepared("CREATE TRIGGER `platform_fee_statements_no_delete` BEFORE DELETE ON `platform_fee_statements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'platform fee statements cannot be deleted; void them instead'");
        }

        // Starting rates (edit in Admin → Settings → Plans & features).
        DB::table('plans')->where('slug', 'free')->update(['commission_percent' => 5]);
        DB::table('plans')->where('slug', 'premium')->update(['commission_percent' => 3]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `platform_fee_statements_no_delete`');
        }
        Schema::dropIfExists('platform_fee_statements');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['commission_percent', 'per_booking_fee']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['commission_percent', 'per_booking_fee']));
    }
};

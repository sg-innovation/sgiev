<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each company chooses its payment gateway: SabPaisa (default) or Razorpay. Keys are stored encrypted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('payment_gateway', 12)->default('sabpaisa')->after('sabpaisa_webhook_secret');
            $table->string('razorpay_key_id', 60)->nullable()->after('payment_gateway');
            $table->text('razorpay_key_secret')->nullable()->after('razorpay_key_id');
            $table->text('razorpay_webhook_secret')->nullable()->after('razorpay_key_secret');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['payment_gateway', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret']));
    }
};

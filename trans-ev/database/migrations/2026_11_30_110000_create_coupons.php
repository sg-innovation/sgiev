<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons & discounts (SGI EV SRS: offers). A coupon is either platform-funded (company_id null, valid at
 * every operator) or operator-funded (valid only at that company's stations). The discount is given at the
 * time of supply and shown on the invoice, so it reduces the GST taxable value (CGST Act s.15(3)(a)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete(); // null = platform-funded
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('type', 10);                                   // percent | flat
            $table->decimal('value', 10, 2);                              // % or ₹
            $table->decimal('max_discount', 10, 2)->nullable();           // cap for percent coupons
            $table->decimal('min_order', 10, 2)->nullable();              // on the pre-tax subtotal
            $table->json('station_ids')->nullable();                      // null = all eligible stations
            $table->string('charger_type', 4)->nullable();                // AC | DC | null
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();           // total redemptions
            $table->unsignedInteger('per_user_limit')->nullable()->default(1);
            $table->boolean('first_booking_only')->default(false);
            $table->boolean('is_public')->default(false);                 // shown as an offer on the booking page
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'ends_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('tariff_id')->constrained()->restrictOnDelete();
            $table->string('coupon_code', 40)->nullable()->after('coupon_id');
            $table->decimal('subtotal_amount', 12, 2)->nullable()->after('session_fee');   // energy + fee, before discount
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal_amount');
            $table->string('discount_funded_by', 10)->nullable()->after('discount_amount'); // platform | operator
            $table->index(['coupon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['coupon_id', 'status']);
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'subtotal_amount', 'discount_amount', 'discount_funded_by']);
        });
        Schema::dropIfExists('coupons');
    }
};

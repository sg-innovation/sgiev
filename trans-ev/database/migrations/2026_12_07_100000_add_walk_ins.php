<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3 walk-ins: drivers who arrive without a booking. Staff start charging from the Staff Console; the bill is made from
 * the energy actually delivered and paid at the station (cash, UPI with reference, or an online link).
 * Walk-in customers need no account, so bookings and payments may have no user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('source', 10)->default('online')->after('code')->index(); // online, walk_in
            $table->string('settlement', 10)->nullable()->after('status');          // walk-ins: due, paid
            $table->foreignId('created_by')->nullable()->after('settlement')->constrained('users')->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('collected_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['source', 'settlement']);
        });
    }
};
